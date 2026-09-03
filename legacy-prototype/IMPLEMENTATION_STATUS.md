# Implementation Status — Read This First

This document follows the same "here's exactly what has and hasn't been
verified" approach used elsewhere in this project's history. Every claim below
is either something that was actually run and observed, or is explicitly
flagged as not done.

## Environment constraints discovered before writing any app code

Checked directly, not assumed, before deciding on an architecture:

| Requirement (from the master prompt) | Status in this sandbox |
|---|---|
| PHP 8.3+ / Composer / Laravel 13.x | **Not available.** No `php` binary at all. |
| `packagist.org` reachable | **No** — not in the network allowlist. Laravel cannot be built here regardless of runtime availability. |
| `pip install <new package>` | **Fails** — "No matching distribution found" for `fastapi`, `pydantic`, `langgraph`, everything tried that wasn't already in the base image. |
| `npm install <new package>` | **Fails** — HTTP 403 from `registry.npmjs.org` for `vue` and everything else tried. |
| Docker / `docker compose` | **Not available.** No `docker` binary. |
| `alpaca.markets` reachable | **No** — not in the network allowlist. No real Alpaca connection is possible here even with valid keys. |
| PostgreSQL / Redis servers running | **Not running** (packages exist in apt but no server was provisioned for this build; SQLite used instead). |
| Pre-installed and usable | Python 3.12, Flask 3.1.3, Node.js 22 (for reference only — no npm packages could be added), `wkhtmltoimage` (used for visual verification). |

Given that, building the literal specified stack (Vue+Laravel+FastAPI+
LangGraph+Alpaca-MCP+Docker) was not possible in this environment. The
alternative was to either (a) write that stack anyway, entirely untested, or
(b) build a smaller real thing on what's actually here and verify it. This
build is (b).

## What's actually built, working, and verified

- **Backend logic (`backend/agents.py`, `backend/market_math.py`)** — real,
  from-scratch implementations:
  - SMA, RSI (Wilder), annualized realized volatility over a synthetic price series.
  - Black-Scholes pricing and Greeks, implemented with only `math.erf` (no
    scipy). **Verified against put-call parity** in `test_black_scholes_put_call_parity`
    — passes.
  - A 5-stage sequential pipeline (Market Analyst → Options Strategy → Risk
    Manager → Execution → Learning) standing in for the LangGraph graph the
    prompt specifies (`langgraph` was not installable — see table above). Each
    stage is a plain, independently-tested Python function; the pipeline is a
    real function-call chain, not a mock returning canned output.
  - Deterministic risk gates: minimum confidence, max loss per trade (% of
    equity), max total exposure (% of equity), kill switch. **Tested**
    (`TestRiskManager`, 5 tests) including confirming the exposure-cap
    rejection actually fires when genuinely over the limit (an earlier test
    run with insufficient exposure silently passed for the wrong reason — this
    was caught and corrected before being included in the suite).
  - Order idempotency: same idempotency key returns the same order and flags
    `idempotent_replay: true` — tested.
  - Live-trading guard (`assert_paper_only`): raises before the app can start
    at all if `TRADING_ENV` isn't `paper`/`demo` — tested.

- **Persistence (`backend/data_store.py`)** — real SQLite (stdlib `sqlite3`),
  not in-memory-only. Survives a process restart. Seeded with 3 open positions
  and 3 closed trades so the first page load isn't an empty state.

- **API (`backend/app.py`)** — real Flask routes, actually started and
  exercised with `curl` against a running process: `/api/health`,
  `/api/account`, `/api/positions`, `/api/trades`, `/api/agents`,
  `/api/decisions/latest`, `/api/decisions/history`, `/api/run` (POST, runs
  the real pipeline), `/api/kill-switch` (POST, toggled both directions and
  confirmed via a follow-up `/api/account` read), `/api/performance`.

- **Frontend (`frontend/*.html`, `frontend/static/`)** — plain HTML/CSS/JS, no
  build step, no CDN dependency (deliberate: this sandbox's own network egress
  to CDNs also returned 403 during development, so nothing here risks failing
  to load wherever this is eventually opened). Four pages: Dashboard, AI
  Agents, Positions, Performance. Wired to the real Flask API via
  `XMLHttpRequest` (see "Bugs found and fixed" below for why not `fetch`).

- **Tests (`backend/tests/test_agents.py`)** — 17 tests, using Python's
  built-in `unittest` (pytest could not be installed — see table above). Last
  run:

  ```
  Ran 17 tests in 0.002s
  OK
  ```

  Covering: market math correctness (incl. put-call parity and vol
  monotonicity), all risk-gate branches (approve, kill-switch, low confidence,
  over max-loss, over exposure cap), the full pipeline running end-to-end for
  5 different symbols without error, the kill switch actually blocking
  execution, learning-agent statistics on both empty and populated history,
  order idempotency, and the live-trading guard.

## Bugs found and fixed during actual testing (not hypothetical — these happened)

1. **`async`/`await` and `fetch()` are not supported** by the headless
   WebKit-based renderer (`wkhtmltoimage`) used to visually verify the pages.
   First screenshot of the dashboard showed an empty sidebar and empty cards —
   confirmed via a minimal isolated test that it was a JS parse failure, not a
   data problem. Fixed by rewriting the API layer on `XMLHttpRequest` wrapped
   in native `Promise`s and converting all `async function`/`await` call
   sites to `.then()` chains. This also makes the frontend more broadly
   compatible generally, not just for this one renderer.
2. **`toLocaleString(undefined, {minimumFractionDigits: 2, ...})` silently
   ignored its options** in the same renderer, returning the bare number with
   no comma grouping or decimal padding. Confirmed with an isolated test.
   Fixed with a manual thousands-separator (regex-based) that has no such
   dependency.
3. An early version of the exposure-cap test used an `open_exposure` value
   that didn't actually exceed the cap once the new trade's risk was added, so
   it "passed" without exercising the rejection path at all. Caught by
   checking the actual returned `reasons` list rather than trusting the
   boolean, then corrected with a value that genuinely exceeds the cap.

These are the kind of "looks right at a glance, wrong when actually run" bugs
the master prompt itself is centrally concerned with agents introducing —
worth stating plainly that this build hit exactly that failure mode twice, and
both were caught only because the pages were actually rendered and the
booleans actually inspected, not because the code "looked correct."

## What is NOT implemented (and why)

- **Laravel / PHP layer** — no PHP runtime, no `packagist.org` access. The
  system-of-record responsibilities the prompt assigns to Laravel are handled
  directly by the Flask app + SQLite instead. This is the single biggest
  architectural deviation from the spec.
- **Vue 3 / Vite / TypeScript / Pinia / Tailwind** — `npm install` of any new
  package failed (403). The frontend is plain HTML/CSS/JS instead. No build
  step, no TypeScript types, no component framework.
- **LangGraph** — not installable. The 5-stage pipeline is a real, tested,
  sequential Python call chain instead of a stateful graph. There is no
  checkpointing, no pause/resume mid-run, no graph-level observability beyond
  the plain function calls and the `agent_runs` table.
- **Real Alpaca integration (Trading API, MCP server, CLI)** — no network path
  to `alpaca.markets`, and no API keys were provided. `backend/mock_alpaca.py`
  is explicitly labeled as mock throughout, matches the master prompt's own
  documented fallback ("use a clearly labeled deterministic demo mode... never
  pretend demo data is live"), but is **not verified against Alpaca's current
  live schema** — its shape is a best-effort approximation from training
  knowledge, not a checked integration.
- **PostgreSQL / Redis** — SQLite is used instead. No queueing, no
  rate-limiting, no pub/sub; the whole app is single-process and synchronous.
- **Authentication** — none. No login page, no sessions, no Sanctum-equivalent.
  Anyone who can reach the Flask port can use the whole app.
- **Docker / Docker Compose** — `docker/Dockerfile` and `docker/compose.yml`
  are written (valid, reasonable configs) but **never run or tested** — there
  is no `docker` binary in this sandbox. Treat them as a starting point, not a
  verified deployment path.
- **GitHub Actions CI** — `.github/workflows/ci.yml` is written but never
  triggered or verified here.
- **E2E tests (Playwright), OpenAPI docs, real-time WebSocket/Reverb updates,
  market-calendar awareness, HMAC-signed inter-service events** — none of
  these exist in this build. The dashboard updates by re-fetching on a button
  click, not by push.
- **9 of the "ten required pages"** are not built: this covers Markets,
  Options Scanner, Watchlist, Orders, Trade History (as a distinct page —
  trades appear inline on Dashboard/Performance), Risk Manager (as a
  standalone settings page), Backtesting, and Settings. Four pages exist:
  Dashboard, AI Agents, Positions, Performance.
- **Confidence score is a documented heuristic, not a model.** It's a
  transparent formula (RSI distance from 50, plus a volatility-regime term),
  explicitly commented in `agents.py` as not a calibrated probability. It
  should not be presented as a trained model's output.
- **"Hackathon" documentation** (`docs/hackathon-writeup.md`,
  `docs/SUBMISSION_CHECKLIST.md`, build-in-public checklist) — not written for
  this build; the framing didn't fit a prototype that was never meant to be
  submitted to an actual hackathon judge under this scope.

## Honest bottom line

This is a working, tested, single-process demo of the trading-agent *decision
logic* (real math, real deterministic risk gates, a real if simplified
multi-stage pipeline) behind a real if simplified full-stack app — not the
production-grade, multi-service, LangGraph-orchestrated, Alpaca-MCP-integrated
platform the master prompt describes. Treat this as a solid vertical slice of
the decision engine and UI, not a hackathon-submission-ready product.
