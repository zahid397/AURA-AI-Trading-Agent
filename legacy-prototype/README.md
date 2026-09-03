> **Archived prototype.** This is the first working version of AURA, built in a heavily network- and runtime-restricted sandbox (no PHP, no Docker, no package-registry access — see IMPLEMENTATION_STATUS.md below). Its deterministic math (Black-Scholes, RSI/SMA/volatility, risk gates) was ported into the new `ai-engine/` FastAPI service at the repo root. Kept here intact, unmodified, for reference and because it still runs standalone if needed.

---

# AURA AI Trading Agent — Working Prototype

This is a **scoped-down, honestly-documented implementation** of the AURA AI
Trading Agent master prompt. It is not the full Vue + Laravel + FastAPI +
LangGraph + Alpaca-MCP platform the original prompt describes. Read
`docs/IMPLEMENTATION_STATUS.md` before assuming anything about what's real.

**Why it's scoped down:** the sandbox this was built in could not install new
pip or npm packages (both registries returned 403 for anything not already
present), had no PHP/Composer runtime at all, no Docker daemon, and no network
path to `alpaca.markets`. Rather than write untested Laravel/Vue/LangGraph/live-Alpaca
code that could not be run or verified here, this build uses what was actually
available — Python's standard library plus Flask (pre-installed) — and everything
in it has been run and checked. See `docs/IMPLEMENTATION_STATUS.md` for the
full honest breakdown of what that trade-off cost.

## What this actually is

A single Flask app that serves both the API and a plain HTML/CSS/JS frontend
(no build step, no framework), running a real 5-stage agent pipeline
(Market Analyst → Options Strategy → Risk Manager → Execution → Learning)
against **simulated** market data and a **mock** (clearly labeled, no network
calls) Alpaca adapter.

The math is real: RSI, SMA, realized volatility, and a from-scratch
Black-Scholes options pricer (checked against put-call parity — see
`backend/tests/test_agents.py`). The risk gates are real, deterministic, and
tested. The market data and broker responses are simulated — there is no live
feed and no live broker in this build.

## Run locally

```bash
cd backend
pip install -r requirements.txt --break-system-packages   # only Flask; see requirements.txt
python3 app.py
```

Open **http://localhost:5001** in a browser. That's it — one process, no
Docker, no database server to start (SQLite file is created automatically on
first run at `backend/aura_demo.db`).

To reset the demo data:
```bash
python3 -c "import data_store as db; db.init_db(reset=True)"
```

## Run the tests

```bash
cd backend
python3 -m unittest tests.test_agents -v
```

Last run: **17/17 passed** (see `docs/IMPLEMENTATION_STATUS.md` for the exact
output). pytest is not installed/installable in the build sandbox, so these
use Python's built-in `unittest` — same assertions, no external dependency.

## Demo login

There is no authentication in this build (see limitations). The app opens
directly to the dashboard with a seeded demo portfolio.

## Connect a real Alpaca paper account

Not implemented in this build — see `docs/IMPLEMENTATION_STATUS.md` and
`backend/mock_alpaca.py`. The adapter is isolated behind a single class
(`MockAlpacaAdapter`) so swapping in a real `alpaca-py` client against a real
paper account is a contained, well-defined piece of follow-up work, not a
rewrite. There is a hard guard (`assert_paper_only`) that refuses to start the
app at all if `TRADING_ENV` is ever set to anything other than `paper`/`demo`.

## Project layout

```
aura/
├── backend/
│   ├── app.py            # Flask app: routes, static file serving
│   ├── agents.py          # the 5-stage pipeline (real logic, see below)
│   ├── market_math.py     # RSI/SMA/volatility + from-scratch Black-Scholes
│   ├── mock_alpaca.py     # mock broker adapter + paper-only guard
│   ├── data_store.py      # SQLite persistence + seed data
│   ├── requirements.txt
│   └── tests/
│       └── test_agents.py   # 17 unit tests, run with `python3 -m unittest`
├── frontend/
│   ├── index.html, agents.html, positions.html, performance.html
│   └── static/{style.css, app.js}
├── docker/                # Dockerfile + compose.yml -- written, NOT run here (no docker binary in sandbox)
├── .github/workflows/     # CI workflow -- written, NOT run here (no way to trigger GH Actions from this sandbox)
└── docs/
    ├── IMPLEMENTATION_STATUS.md   # what's real, what's mocked, what's missing
    └── demo-script.md
```

## Verification (what was actually run, not just written)

- `python3 -m unittest tests.test_agents -v` → **17/17 tests passed**.
- Started the Flask server and hit every endpoint with `curl`: health, account,
  positions, agents, a triggered pipeline run (for SPY, NVDA, TSLA), the kill
  switch (toggled both ways and confirmed via `/api/account`), and performance
  stats — all returned correct, internally-consistent JSON.
- Rendered all four pages with a headless renderer and visually inspected the
  output. This caught two real bugs during development (documented in
  `docs/IMPLEMENTATION_STATUS.md`): `async`/`await` and `fetch()` are not
  supported by that renderer's JS engine, and `toLocaleString` silently ignored
  its formatting options — both are now fixed with broadly-compatible code
  (`.then()` chains + `XMLHttpRequest`, and a manual thousands-separator).

## Disclaimer

Paper trading only. Educational demonstration — not investment advice. Every
number in this build is simulated.
