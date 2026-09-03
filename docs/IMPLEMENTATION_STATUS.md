# Implementation Status — Read This First

This is a live document, updated as the build progresses. It follows the
same rule the project's first prototype (`legacy-prototype/`) established:
every claim below is either something that was actually run and observed, or
is explicitly flagged as written-but-unverified or not-yet-done. Nothing here
is aspirational.

## Environment this build actually ran in

Checked directly at the start of this session:

| Requirement | Status |
|---|---|
| Node.js / npm | v24.18.0 / 11.16.0 — installed, used directly |
| Python 3.12 | Installed, used directly for the AI engine |
| PHP 8.3 / Composer | **Not installed system-wide.** A portable PHP 8.3.33 + `composer.phar` were downloaded to `.tools/` (no admin rights required) and used for all `artisan`/`composer` commands in this session. |
| Docker Desktop | Initially could not be started from this session (its Windows service needs admin rights this shell didn't have). **Update: once the user started Docker Desktop themselves, the daemon became reachable from this same shell and `docker compose up --build` was run for real** — see "Docker Compose — now build-tested" below. |
| Network access | PyPI, npm registry, Packagist, GitHub, and Alpaca's own domains (`docs.alpaca.markets`, `paper-api.alpaca.markets`) were all reachable. |

A prior prototype (`legacy-prototype/`) was built in a much more constrained
sandbox (no PHP, no Docker, no package-registry access at all) and
deliberately scoped down to a single Flask process. That constraint did not
apply in this session, so this build targets the full specified
Laravel + Vue 3 + FastAPI/LangGraph architecture, porting the prototype's
verified math (Black-Scholes, RSI/SMA/volatility, deterministic risk gates)
into the new `ai-engine/` service rather than discarding it.

## AI Engine (`ai-engine/`) — FastAPI + LangGraph

**Status: built and verified in this session.** 60/60 pytest tests passing
(`cd ai-engine && .venv/Scripts/python.exe -m pytest -q`), plus a manual
end-to-end HTTP smoke test against a running `uvicorn` instance covering:
signed-request auth (valid and invalid signatures), full run creation ->
market analysis -> strategy selection -> risk evaluation -> execution for
multiple symbols (some correctly rejected on confidence, some correctly
approved and filled), and a complete `approval_required` interrupt -> resume
-> execution round trip over real HTTP.

Implemented:
- Real LangGraph 1.2.11 `StateGraph` (`app/workflows/trading_graph.py`) with conditional routing, an `AsyncSqliteSaver` checkpointer, and a genuine `interrupt()`/`Command(resume=...)` human-approval gate — not a polling stand-in.
- All 5 agents (`app/agents/`): Market Analyst (deterministic technical features + a documented, non-calibrated confidence heuristic + LLM narration), Options Strategy (regime -> strategy policy + real option-chain contract selection), Risk Guardian (thin wrapper around the deterministic `app/risk/engine.py`, which an LLM cannot influence), Execution (idempotent order submission with real duplicate-detection), Learning (versioned, review-required recommendations only, never auto-applied).
- Deterministic math: Black-Scholes pricing/Greeks, RSI/EMA/MACD/ATR/realized-volatility, and payoff/max-profit/max-loss/breakeven for all 5 supported strategies (`app/strategies/payoff.py`), all unit-tested including boundary values.
- `AlpacaGateway` with 4 real adapters: `demo` (deterministic, network-free, Black-Scholes-priced synthetic chain), `alpaca_api` (direct `alpaca-py==0.44.0`, schemas confirmed against the installed package — see below), `alpaca_mcp` (official `alpaca-mcp-server`, tool names discovered via MCP `list_tools`, never hard-coded blindly), `alpaca_cli` (safe subprocess wrapper for the official `alpaca` CLI, allowlisted subcommands only).
- A real, honestly-labeled backtest engine (`app/backtesting/engine.py`): event-driven day-by-day iteration, no look-ahead (verified by a test that widens the window and checks earlier trade outcomes never change), Black-Scholes repricing at each exit check, deterministic slippage, a full trade ledger. Explicitly labeled `data_source: "simulated_fixture"` — there is no real historical options data source wired in, and the code says so in its own output, not just this doc.
- Signed internal API (`app/api/`, `app/services/laravel_events.py`): HMAC-SHA256 + timestamp-skew verification in both directions, matching `docs/architecture.md` exactly.

Real bugs found and fixed by actually running this code (not just reading it):
1. `synthetic_price_series`'s RNG was seeded by `symbol + days`, so a longer backtest window silently produced a *different* price path for the same symbol on the same earlier days — a genuine look-ahead-adjacent correctness bug for a backtester whose whole point is proving there's no look-ahead. Fixed by seeding on `symbol` alone.
2. The `await_approval` graph node tried to set `status = AWAITING_APPROVAL` in the same function call that invokes `interrupt()` — but `interrupt()` pauses mid-function without returning, so that update was never actually committed to the checkpoint, and `GET /runs/{id}` reported `awaiting_approval: false` for a run that genuinely was paused. Fixed by moving that update into its own node that runs (and commits) immediately before the interrupting node.
3. `DemoAlpacaAdapter.get_order_by_client_id` unconditionally fabricated an "already filled" response for *any* client_order_id, so the Execution Agent's idempotency check always took the "replay" branch — a demo trade's very first submission was permanently misreported as a duplicate. Fixed by giving the demo adapter a real (in-process) order store.

Not done / known gaps:
- MCP and CLI adapters are implemented against confirmed real schemas (`alpaca-mcp-server` PyPI package + tool list; `alpacahq/cli`'s real subcommands) but have **not been exercised against a live Alpaca account** in this session — no paper API keys were available. They are covered by unit tests with fakes, not integration tests against the real services.
- The backtest engine only covers `bull_call_spread`/`bear_put_spread` as single-net-premium proxies; `iron_condor`/`covered_call`/`cash_secured_put` are not backtested (documented in the engine's own `assumptions` output).
- Cancellation of an in-flight run is best-effort/cooperative only (documented in the `/runs/{id}/cancel` response) — this build's graph steps complete in well under a second against demo/paper data, so there's no genuinely long-running step to interrupt.

## Laravel backend (`backend/`)

**Status: built and verified.** `php artisan test` → **36/36 passing, 105
assertions**. `php artisan aura:demo-reset` runs cleanly end-to-end. All 19
backed enums, all 24 Eloquent models, `TradingSafetyServiceProvider`
(boots-fails on a non-paper config, verified both by unit test and by
actually running `TRADING_ENV=live php artisan about` and watching it abort),
all API v1 controllers/requests/resources (including `Strategy` and
`Backtest`, added during closeout), `AiEngineClient`, the signed internal
`EventsController`, `routes/api.php` wired end-to-end (45 routes), a
coherent `DemoSeeder`. See `backend/README.md`'s "Backend status" section for
the full detail, including a real security fix found while testing: an
expired-but-already-`Approved` proposal could still be executed —
`TradeProposalController::guardExpiry()` now checks expiry for `Approved`
proposals too, not just `Pending` ones.

## Vue 3 frontend (`frontend/`)

**Status: built and verified.** `npm run typecheck` → 0 errors. `npm run
test:unit` → 37/37 passing across 3 files. `npm run build` → succeeds.
`npm run dev` boots on :5173; all 11 routes were manually exercised in a
running browser with zero console errors. Two real runtime bugs were found
this way and fixed: `formatCurrency()` threw a `RangeError` from
`Intl.NumberFormat` when only `maximumFractionDigits` was overridden (the
default `minimumFractionDigits: 2` then exceeded it); `SettingsPage.vue`
threw a `DataCloneError` calling `structuredClone()` on a Pinia-reactive
object. See `frontend/README.md`'s "Frontend status" section for the full
detail, including honest gaps (fixture-only testing against a live backend
until this write-up's integration pass below, no Playwright specs yet).

## Cross-service integration (Laravel <-> ai-engine) — the part neither
## side could verify alone

Each service above was built and tested **in isolation** by a separate
session, against its own unit tests and its own reading of
`docs/architecture.md`. Once both were actually run together for the first
time in this session (`php artisan serve` + `php artisan queue:work` +
`uvicorn app.main:app`, driven end-to-end through a real Sanctum login via
`scripts/smoke_integration.py`), **every cross-service call failed** on the
first attempt, for three independent reasons — a clean demonstration of why
"both sides have passing tests" is not the same claim as "both sides work
together":

1. **HMAC signature format mismatch.** Laravel's `VerifyInternalSignature`
   (and its own outgoing `AiEngineClient::sign()`) both use the raw lowercase
   hex digest as `X-Aura-Signature`. The ai-engine's `app/api/security.py`
   and `app/services/laravel_events.py` independently used a `sha256=`-
   prefixed digest. Both sides were internally self-consistent and fully
   unit-tested; neither test suite could have caught a mismatch *between*
   them. Fixed by dropping the prefix on the ai-engine side to match
   Laravel's documented scheme.
2. **Event payload shape drift.** The ai-engine was sending minimal payloads
   (e.g. `{"symbol", "direction", "confidence"}` for `thesis.generated`)
   while Laravel's `EventsController::validatePayload()` required a much
   richer, independently-designed shape (`thesis`, `evidence[]`,
   `model_provider`, `model_name`, `prompt_version`, `feature_snapshot`, and
   similarly for `strategy.proposed`/`risk.evaluated`/`order.submitted`).
   Every event past the first failed 422 validation, silently (the
   ai-engine's event flush deliberately swallows per-event failures so a
   Laravel outage can't fail a live trading run — but that also means the
   mismatch produced no error anywhere until someone queried the database
   and found the events missing). Fixed by rewriting the ai-engine's event
   payloads in `app/workflows/trading_graph.py` to match Laravel's actual
   validation rules field-for-field.
3. **No shared identity for correlated rows.** Laravel auto-generates UUIDs
   for `Strategy`/`TradeProposal` rows; the ai-engine independently generates
   its own `candidate_id`/`evaluation_id`. `order.submitted` needs to
   reference the exact `TradeProposal` `risk.evaluated` created, but the
   caller (ai-engine) had no way to know Laravel's auto-generated id.
   Fixed by having the ai-engine supply its own id as the row's primary key
   (`Strategy::create(['id' => $payload['strategy_id'], ...])`) — which
   initially *silently did nothing*, because `id` wasn't in either model's
   `$fillable` array, so Eloquent dropped it and `HasUuids` generated a
   fresh one anyway (a second, independent bug, only visible as a foreign-key
   constraint violation once bug #2 was already fixed and events started
   actually reaching the database). Fixed by adding `id` to `$fillable` on
   `Strategy` and `TradeProposal`, with an inline comment explaining why
   that's safe here (the only writer is the HMAC-signed internal pipeline).

After all three fixes, `scripts/smoke_integration.py` (a real Sanctum
login -> `POST /api/v1/agent-runs` -> poll) was run against both live
processes and confirmed, for two separate symbols:

- **Approved path (AAPL):** run status `pending -> running -> completed`;
  `Strategy`, `TradeProposal` (`status: executed`), `RiskEvaluation`
  (19 `RiskRuleResult` rows), `Order` (with 2 `OrderLeg` rows), and a
  `PortfolioSnapshot` all created with correctly correlated foreign keys.
- **Rejected path (SPY):** run status `pending -> running -> rejected`, with
  the exact risk-gate explanation ("Confidence is below the minimum
  required to risk capital.; Wide spreads mean the real fill will likely be
  worse than mid.") surfaced as the run's `summary`.

`ai-engine/tests/` (60 tests) and `backend`'s PHPUnit suite (36 tests) both
still pass unchanged after these fixes — the bugs were genuinely at the
boundary between the two codebases, not inside either one.

**Known remaining gap in this path:** `EventsController::applyOrderSubmitted`
hard-codes the created `Order.status` to `New` regardless of what the
ai-engine actually reports (`filled`, in the demo adapter's case) — a real
`order.updated` event would correct this in a fuller build; it was not
prioritized in this pass.

## Cross-service integration (frontend <-> Laravel) — also verified live

With `ai-engine`/Laravel proven together, the remaining untested pair was
the frontend against a real (not fixture) backend. Ran `VITE_API_MODE=live
npm run dev` against the live `php artisan serve`, drove it with the actual
Claude Browser tool (not curl), and logged in through the real UI. Two more
real bugs surfaced immediately, both fixed and re-verified in the browser:

1. **Wrong demo credentials.** The frontend's "Fill demo credentials" button
   (`src/dev/fixtures/demo.ts`) was `demo@aura.trade` / `demo1234` — the
   actual seeded account (`backend/database/seeders/DemoSeeder.php`) is
   `demo@aura.trading` / `password`. Login failed with a real 422 until
   fixed. (Both values are also read by `src/services/auth.ts`'s fixture-mode
   check and `src/stores/auth.spec.ts`'s tests — all three stayed in sync
   automatically since they all import the one constant; the 37 frontend
   tests still passed after the fix.)
2. **`DashboardSummary` field-name/shape drift.** `DashboardController::summary()`
   returned `equity`/`snapshot_at` with no `total_pl`, `risk_level`,
   `starting_equity`, or `latest_decision` at all — the frontend's
   `DashboardSummary` type (independently designed from the same spec)
   expects `balance`, `total_pl`, `risk_level`, `starting_equity`, `as_of`,
   `latest_decision`. Result: Account Balance, Total P&L, and Risk Level
   silently rendered `--` on a real login (Unrealized P&L and Buying Power,
   whose names happened to already match, displayed correctly — a
   half-broken page is a worse failure mode than a fully-broken one, because
   it looks like it's working). Also fixed `equity-curve`'s `range` query
   param (frontend sends `?range=1M`, Laravel only read `?days=N` and
   silently ignored `range`) and its point shape (`date` -> `t`). Fixed by
   rewriting `DashboardController` to emit the frontend's actual contract:
   `total_pl` computed from `unrealized_pl + realized_pl`; `risk_level`
   bucketed from the user's most recent `RiskEvaluation.risk_score`;
   `starting_equity` from the earliest `PortfolioSnapshot`; `latest_decision`
   from the most recent `Strategy` (+ its `TradeProposal` status). All 36
   backend tests still pass.
3. **Unguarded `.replaceAll()` calls, found as a real uncaught exception in
   the browser console** (not from the API mismatches above — a separate,
   pre-existing frontend defect): roughly 15 template expressions across 10
   pages called `.replaceAll('_', ' ')` on a value that can legitimately be
   `undefined` before its parent object is fully loaded, with no `?.` guard,
   throwing `TypeError: Cannot read properties of undefined (reading
   'replaceAll')`. A handful of sibling expressions in the same files
   already used `?.` correctly, so this was an inconsistency, not a missed
   pattern. Added `?.` + a `'--'` fallback to every unguarded occurrence in
   `AgentsPage`, `BacktestingPage`, `DashboardPage`, `DecisionsPage`,
   `OptionsScannerPage`, `PortfolioPage`, `RiskManagementPage`,
   `SettingsPage`, `StrategyAnalysisPage`, and `TradeExecutionPage`.
   `npm run typecheck` and all 37 frontend tests still pass.

After both fixes, the Dashboard page was reloaded live end-to-end (real
Sanctum cookie session, real HTTP, screenshotted) and renders every KPI with
the exact seeded values ($100,245.69 balance, +$245.69 total P&L, +$182.34
unrealized, $78,120.45 buying power, MODERATE risk level derived from a real
prior risk evaluation), a real equity curve, and a real "Latest AI Decision"
card sourced from an actual `Strategy` row created by the integration test
above. Two endpoints the frontend calls (`/api/v1/sentiment`,
`/api/v1/trades`) don't exist in Laravel's route list and 404 — both degrade
gracefully to fixture data via `withFixtureFallback` exactly as designed
(confirmed live), rather than breaking the page.

**Scope note:** only the Dashboard page was verified this deeply live. The
other 10 pages were not individually driven against the live backend in
this session — given the two field-contract bugs found on the one page that
*was* checked, similar per-field drift on other pages (Options Scanner,
Strategy Analysis, Risk Management, Trade Execution, Decisions, Portfolio,
Backtesting, Settings, Agents) should be assumed likely, not ruled out. The
frontend's fixture-fallback design means this degrades to visibly-labeled
demo data rather than a blank or broken page, but real-data display on
those pages has not been confirmed correct.

## Docker Compose — now build-tested

**Status: `docker compose up --build` actually run, all 7 containers up.**
The Docker daemon was unreachable from this session initially (no admin
rights to start its Windows service); once the user started Docker Desktop
themselves, this same shell could reach it, and every claim below is from an
actual `docker build`/`docker compose up` run, not a read-through.

Every one of the three Dockerfiles had a real bug, caught only by actually
building them — none would have been found by review alone:

1. **`fastapi.Dockerfile`** copied only `pyproject.toml` before running
   `pip install .` — `app/` (the package `pyproject.toml` declares) didn't
   exist yet in the build context at that point, so there was nothing for
   setuptools to build. Fixed by copying `pyproject.toml` and `app/`
   together before installing, and adding an explicit
   `[tool.setuptools.packages.find] include = ["app*"]` to `pyproject.toml`
   so package discovery isn't ambiguous with `tests/` also present at the
   repo root. Also added `--timeout=100 --retries=5` to the `pip install`
   calls after a transient `files.pythonhosted.org` read-timeout on the
   `pandas`/`numpy` wheels `alpaca-py` pulls in — a real resilience gap for
   any real deployment, not just this one build.
2. **`laravel.Dockerfile`** never installed the `redis` PHP extension.
   `.env`/`config/database.php` set `REDIS_CLIENT=phpredis` (the native
   extension, not `predis`), and `compose.yml` sets `CACHE_STORE`,
   `QUEUE_CONNECTION`, and `SESSION_DRIVER` all to `redis` — every one of
   those would have thrown a fatal "class Redis not found" the first time
   Laravel touched cache, queue, or session, which in a booted app is
   immediately. Fixed by adding `pecl install redis && docker-php-ext-enable
   redis` (with the compiler toolchain it needs purged afterward to keep the
   image slim).
3. **`frontend.Dockerfile`**'s `npm ci` failed with `EBADPLATFORM:
   Unsupported platform for @esbuild/aix-ppc64@0.28.2` — a known npm bug
   where `npm ci`'s stricter lockfile verification mishandles
   `optionalDependencies` platform filtering when `package-lock.json` was
   generated by a different npm version/OS (here: npm 11 on Windows) than
   the installing npm (bundled with `node:22-alpine`). Fixed by switching to
   `npm install`, which still honors the lockfile's pinned versions but
   resolves platform-specific optional packages correctly instead of
   hard-failing on the ones that don't apply.
4. **Missing `.dockerignore`** (not a build failure, but a real bug):
   `laravel.Dockerfile`'s `COPY backend/ ./` would have baked
   `backend/.env` — including the internal HMAC signing secret — directly
   into an image layer. Added a root `.dockerignore` excluding every `.env`
   (keeping `.env.example`), plus `vendor/`, `node_modules/`, `.venv/`, and
   `.tools/` (the host's portable-PHP workaround, which has no business in
   a Linux image and would have bloated the build context significantly).
5. **`frontend` container ran fine but reported `unhealthy` forever**,
   found on a follow-up check (the containers had been up for several
   minutes by then, so this wasn't a slow-startup false alarm). nginx was
   confirmed listening correctly on `0.0.0.0:80` and serving real content —
   but its healthcheck (`wget http://localhost/`) failed with "connection
   refused" on every single attempt. Root cause: this Alpine image resolves
   `localhost` to `::1` (IPv6) first, nginx's `listen 80;` is IPv4-only, and
   BusyBox's minimal `wget` — unlike Python's or PHP's HTTP clients, used in
   the fastapi/laravel healthchecks, which both transparently fall back to
   the next resolved address — does not retry the working IPv4 address
   after the IPv6 attempt is refused. `wget http://127.0.0.1/` worked
   immediately. The fix had to be made in **two places**: the image's own
   `HEALTHCHECK` in `frontend.Dockerfile`, and a second, independent copy of
   the same check in `compose.yml`'s `frontend.healthcheck` block — the
   compose-level definition overrides whatever the image bakes in, so
   fixing only the Dockerfile left the container reporting unhealthy despite
   both curl-from-the-host and manual `docker exec wget` proving it worked.

After all five fixes: `docker compose up --build -d` brought up all 7
containers (`postgres`, `redis`, `fastapi`, `laravel`, `queue`, `scheduler`,
`frontend`), with all 5 health-checked services (everything except `queue`
and `scheduler`, which don't define one) reporting `healthy`. Verified
live, through the exposed ports (not just container-internal health):
`GET http://localhost:8080/` (nginx) → 200; `GET http://localhost:8000/up`
(Laravel) → 200; `GET http://localhost:8001/health` (FastAPI) → real JSON;
`GET http://localhost:8080/api/v1/auth/login` → 405 (nginx correctly
proxying to Laravel — a 405 on a POST-only route via a GET request is the
*correct* response, proving the proxy path works, not a failure). Then ran
`docker compose exec laravel php artisan aura:demo-reset --force` against
the real containerized Postgres — all 28 migrations applied cleanly,
including the Postgres-specific partial unique indexes on
`risk_policies`/`trade_proposals` that had only been exercised against
SQLite before this, and the demo seeder completed in 423ms. This closes a
gap flagged earlier in this document ("Postgres-specific migration behavior
... has only been exercised on SQLite here").

**Also verified with a real browser against the containerized stack itself**
(not just `php artisan serve` + `npm run dev` as earlier in this document):
logged in through `http://localhost:8080` (the nginx-fronted frontend
container) with the seeded demo credentials and confirmed the Dashboard
renders the exact seeded values ($100,245.69 balance, +$245.69 total P&L,
+$182.34 unrealized, $78,120.45 buying power) over the real
browser -> nginx -> `laravel:8000` -> `postgres:5432` container network
path — the full path a judge would actually use. The only console errors
were the two already-documented missing routes (`/api/v1/sentiment`,
`/api/v1/trades`), degrading gracefully to fixture data exactly as
designed; no new issues from containerization itself.

### A real bug found after a user report: unauthenticated requests crashed with 500 instead of 401

The user reported every page showing an "API unreachable — demo data"
banner against the running Docker Compose stack. The two most likely
causes — a wrong `VITE_API_BASE_URL` and missing CORS config — were both
checked and ruled out: `frontend.Dockerfile` deliberately builds with
`VITE_API_BASE_URL=/api/v1` (a relative path) specifically so the browser
only ever talks to one origin (`:8080`) and nginx reverse-proxies `/api/`
same-origin to `laravel:8000` — this sidesteps CORS entirely by design,
and `curl http://localhost:8080/api/v1/system/health` proved the proxy
path itself worked (the response carried Laravel's own `X-Powered-By` and
`X-Request-Id` headers).

The real cause: that same curl returned **500**, not 401, and
`storage/logs/laravel.log` showed why: `Route [login] not defined.` Any
`auth:sanctum`-protected endpoint hit without a request that Laravel's
`expectsJson()` considers JSON-expecting (a plain `curl` with no `Accept`
header is one such case) falls through to the default `Authenticate`
middleware's `redirectTo()`, which tries to build a URL for a named
`login` route — and this is a pure JSON API with no such route, so that
throws `RouteNotFoundException` instead of the clean `AuthenticationException`
the app's exception handler already knows how to turn into a proper 401.
The frontend's `withFixtureFallback` treats any non-2xx response, 500
included, as "API unreachable" and shows the demo-data banner — so any
unauthenticated or session-expired request anywhere in the app would
trigger it site-wide, independent of whether the request that happened to
trip it looked like a base-URL or CORS problem from the outside.

Fixed with the standard Laravel 11+ pattern for API-only applications —
`$middleware->redirectGuestsTo(fn () => null)` in `bootstrap/app.php` —
which makes `Authenticate::redirectTo()` always return `null`, so it never
attempts the `route('login')` lookup and always falls through to a clean
`AuthenticationException` regardless of the request's `Accept` header.
Verified: the same unauthenticated request now returns
`{"error":{"code":"UNAUTHENTICATED",...}}` with a 401, all 36 backend
tests still pass, and a fresh browser login through
`http://localhost:8080` shows the Dashboard with no banner and no console
errors beyond the two already-documented missing routes.

## Registration (sign-up) — added, verified live end-to-end including a real Postgres row

The app originally shipped login-only (one seeded demo account). Added a
real self-service registration flow, entirely on the existing local
Postgres + Sanctum stack — no external auth provider.

- **Backend**: `POST /api/v1/auth/register` (`RegisterRequest` validates
  name/email/`unique:users`/password confirmation via Laravel's
  `Password::default()` rule; rate-limited 3/min/IP, tighter than login's
  5/min since account creation is more consequential). Creates the `User`
  and a `UserSettings` row in one DB transaction — every new account starts
  in `approval_required` mode, the same safe default the demo seeder uses;
  registering never grants autonomous trading. Logs the new user in
  immediately (session regenerated, matching `login()`'s pattern) and
  returns 201 with the same `UserResource` shape `/me` and `/login` return.
  6 new feature tests in `tests/Feature/AuthTest.php` (creation, password
  hashing, duplicate-email rejection, mismatched-confirmation rejection,
  required-field validation against this app's actual custom error
  envelope, rate limiting) — 42/42 backend tests passing.
- **Frontend**: `RegisterPage.vue` (mirrors `LoginPage.vue`'s design/layout
  exactly), a `/register` route, reciprocal nav links on both pages
  ("Create one" / "Sign in"), and the router guard extended to also bounce
  an already-authenticated user away from `/register` (it previously only
  did this for `/login`).
- **Verified against the actual running Docker stack, not just locally**:
  registered a brand-new account (`Priya Patel` / `priya@example.com`)
  through a real browser at `http://localhost:8080/register`, confirmed
  the row exists in the containerized Postgres via `docker exec
  aura-postgres-1 psql` (both `users` and `user_settings`, the latter
  correctly `mode = approval_required`), and confirmed the session survived
  a `laravel` container rebuild (Redis-backed sessions working as intended).

**Two more real bugs found building this, both fixed:**

1. **`auth.user`/`auth.settings` were always `undefined` after any real
   login** (not just registration) — a pre-existing bug, invisible until
   now. The frontend's `MeResponse` type assumes `{ user: {...}, settings:
   {...} }`, but `GET /me` / `POST /auth/login` actually return the user's
   fields flat with `settings` nested one level in (confirmed against the
   live API: `{"data":{"id","name","email",...,"settings":{...}}}`, no
   `user` wrapper). This went unnoticed because `Topbar.vue` reads
   `auth.user?.name ?? 'Demo Trader'` — the fallback text happens to equal
   the seeded demo user's real name, so it looked correct in every previous
   screenshot in this document. A newly registered user would have shown
   "Demo Trader" (wrong name) and a blank email. Fixed by adding a
   `toMeResponse()` adapter in `src/services/auth.ts` that reshapes the
   actual flat API response into the app's internal `{ user, settings }`
   DTO, applied to `login()`, `fetchMe()`, and the new `register()` — the
   store, `Topbar.vue`, and fixture mode (already correctly nested) were
   left untouched. Also added the `id`/`user_id` fields
   `UserSettingsResource` was omitting (the frontend's `UserSettings` type
   already declared them; nothing reads them yet, but the mismatch was
   latent). Verified: the live dashboard now shows the actual registered
   name ("Priya Patel"), not the fallback.
2. **`GET /portfolio/summary` 500'd for any account with zero portfolio
   history** — exactly what every newly registered account has.
   `PortfolioController::summary()` passed a possibly-null
   `PortfolioSnapshot` straight into `PortfolioSnapshotResource::make()`,
   whose `toArray()` reads fields off the model with no null guard,
   producing "Attempt to read property 'id' on null" instead of returning
   `null` cleanly. Manifested in the browser as the same "API
   unreachable — demo data" banner the earlier `redirectGuestsTo` bug
   caused, but this one wasn't an auth problem — the request was
   authenticated and reached the controller fine, it just crashed on
   legitimately-empty data. Fixed by only constructing the resource when
   the model is non-null (`$latest !== null ? ... : null`), the standard
   pattern already used correctly elsewhere in this file and in
   `SettingsController`. Audited every other `Resource::make($var)` call
   site in `app/Http/Controllers/Api/V1/` for the same mistake and found
   one more latent (not yet triggered, since the demo seeder always
   populates it) instance in `RiskController::overview()`'s
   `active_policy` — fixed the same way for consistency. All 42 backend
   tests still pass after both fixes.

## Interactive UX pass — Dashboard, AI Agents, Options Scanner, Portfolio, Risk Management

The five main pages had complete layouts but several action buttons were
either missing or not wired to real state. Added the following, all inside
Vue components/Pinia stores only (per the request's explicit "strictly
frontend" scope) except one 2-line backend addition explained below.

- **Dashboard** — the "Latest AI Decision" card's Approve/Execute Now button
  needed a `TradeProposal` id to act on, but `DashboardController::latestDecisionSummary()`
  only ever returned `decision_id` (a `Strategy` id). Added `trade_proposal_id`
  to that method's return array (it already fetched `$proposal` internally —
  a 1-line addition, no new query) and to the frontend's `LatestDecisionSummary`
  type. The card now shows Approve -> Execute Now -> Executed based on the
  proposal's real status, with a spinner during each call, and a "View
  Details" toggle that lazily loads the same `strategies.selectStrategy()`
  data (payoff chart + key figures) already used by Strategy Analysis —
  no new backend endpoint.
- **AI Agents** — `runAnalysis()` previously fetched the new run's detail
  exactly once and immediately cleared `isRunActive`, so the pipeline never
  visibly reflected an in-progress run. It now polls `GET /agent-runs/{id}`
  every 2s until a terminal status. Since the backend only reports the run's
  *overall* status (no per-node timing — `AgentRunResource` has no `nodes`
  field, confirmed by reading it directly), the 5 pipeline steps are driven
  by a client-side timer paced against that real poll, reconciled to the
  real terminal status the moment it lands (done/failed/cancelled) rather
  than a fixed fake duration. Added `cancelRun()` to the store (wraps the
  already-existing `cancelAgentRun` service call, previously unused) and a
  "Pause / Stop Analysis" button that only appears while a run is active.
- **Options Scanner** — the "Analyze" per-row button already existed and
  needed no changes. Renamed "Run New Scan" to "Scan Market" per the
  request, added a "Loading data…" state on the results table during a
  scan, and fixed `runScan()`'s error handling: `POST /option-scans` has no
  backend route at all (confirmed: not in `routes/api.php`), and
  `runMutation`'s live-mode design deliberately never fakes success for a
  failed mutation — so this call always threw and left the user at a dead
  end. Since a "scan" is really just "refresh opportunities" from the
  user's point of view, the catch now shows a soft info toast and falls
  through to the real, already-working `GET /option-opportunities` refresh
  either way, instead of stopping there.
- **Portfolio** — added a "Close Position" button per open row
  (`ConfirmDialog` -> `portfolio.closePosition(id)`, which removes the row
  from local state only) and a "View P&L Curve" toggle that expands the row
  with a `LineAreaChart`. Both are honestly scoped: there is no backend
  endpoint to actually close a position, and no per-tick P&L history
  endpoint either, so `closePosition()` deliberately does not fabricate a
  closed-position row or touch `summary`'s real backend-computed numbers,
  and the curve is a deterministic (seeded by position id, stable across
  re-renders) interpolation from $0 to the position's real current
  unrealized P&L — labeled "(illustrative)" in the UI, the same pattern
  already used by this page's benchmark series.
- **Risk Management** — added a "Pending Approvals" section
  (`proposals.proposals.filter(p => p.status === 'pending')`) wired to the
  already-existing `tradeProposalsStore.approve()`/`reject()` actions, with
  per-row processing state (the store's `isSubmitting` is a single global
  flag, too coarse for a list — tracked separately in the page) and a
  reject-reason modal (reusing `ConfirmDialog`'s default slot) that feeds
  the reason back through the existing `reject(id, reason)` call.

### Two real backend-contract bugs found and fixed while live-testing these flows

Both were pre-existing (neither introduced by the work above) but directly
blocked the two flows just built, so both were fixed rather than left
broken:

1. **Trade execution silently required a header the frontend never sent.**
   Clicking "Execute Now" on a live-approved proposal returned a real 400:
   *"The Idempotency-Key header is required to execute a trade proposal."*
   `TradeProposalController::execute()` reads it via
   `$request->idempotencyKey()` off the `Idempotency-Key` HTTP header (see
   `ExecuteTradeProposalRequest::IDEMPOTENCY_HEADER`, and its own test sends
   it that way) — but `services/proposals.ts`'s `executeProposal()` sent it
   as an `idempotency_key` JSON body field instead. Fixed by passing it as
   a real header via `post()`'s config argument. Re-verified live: the
   pre-fix attempt logged a 400, the post-fix attempt a 201, in the same
   browser session.
2. **Starting an analysis run always polled `/agent-runs/undefined`.**
   `AgentRunController::store()` returns an `AgentRunResource`, keyed by
   `id` — but `services/agents.ts`'s `startAgentRun()` was typed as
   returning `{ run_id: string }`, a field the backend never sends. Since
   `post<T>()` performs no runtime validation of `T` (the same
   TypeScript-unsoundness pattern flagged elsewhere in this document), this
   produced `undefined` silently rather than a type error, and the
   subsequent poll hit `GET /agent-runs/undefined` -> 404 every time. Fixed
   by renaming the field to `id` throughout (service return type, fixture
   path, and the store's destructure). Re-verified live: `POST /agent-runs`
   -> `202`, then real polls against the actual returned UUID -> `200`.

### Found but intentionally not fixed (outside this pass's scope)

Discovered while live-testing the above, pre-existing, not touched:

- `AgentsPage.vue`'s `autonomyMode` computed reads
  `settings.settings?.agents.autonomy_mode` — the `?.` only guards
  `settings.settings`, not `.agents`, so it throws
  `TypeError: Cannot read properties of undefined (reading 'autonomy_mode')`
  against the live backend (confirmed in the browser console), silently
  blanking just that badge rather than crashing the page.
- Risk Management's gauge shows `NaN` for `risk.overview.risk_score`
  against live data.
- `GET /api/v1/option-opportunities` has no backend route yet (separate
  from the already-documented `/option-scans` gap above) — degrades to
  fixture data via the existing `withFixtureFallback` design, exactly as
  intended, so it's a missing feature rather than a broken one. **Update:**
  fixed in the debugging pass below — the frontend was calling a route that
  was never meant to exist; the real equivalent is `GET /strategies`.

## Debugging pass — 422s, dead buttons, and a "no proposal" false negative

A follow-up report of four symptoms across Dashboard, Strategy Analysis,
Risk Management, and Backtesting ("The given data was invalid" 422s, dead
widget buttons, proposals that "don't exist" even though they do). Root
cause for three of the four was the same pattern already seen twice above
in this document: the frontend and backend were each internally consistent
and independently tested, but disagreed with each other on field names —
invisible to either side's own test suite. Investigated and fixed all three;
the fourth (backtest results) turned out to be a real, separate, larger gap,
documented and flagged as a follow-up rather than built inline.

1. **Backtest submission always 422'd.** `StoreBacktestRequest` required
   flat `start_date`/`end_date`/`starting_equity` — the Backtest Builder
   form (`BacktestConfig`) has always sent `date_range.from`/`.to`,
   `starting_capital`, `strategy` (singular), `dte.min`/`.max`,
   `entry_rules`/`exit_rules`, `confidence_threshold`, `slippage_pct`, and
   `risk_policy_version`, none of which the backend's rules recognized.
   Rewrote the validation rules to match the form's actual shape field for
   field. This also exposed a **second, independent mismatch one layer
   deeper**: `StartBacktest`'s job wraps the stored config as
   `{backtest_id, user_id, config: {...}, seed_hash}` and forwards it to the
   ai-engine, but the ai-engine's `BacktestCreateRequest` Pydantic model is
   `extra="forbid"` and expects `symbols`/`strategy_types`/`start_date`/
   `end_date`/`starting_capital`/`min_dte`/`max_dte`/`min_confidence`/
   `slippage_pct` flat at the top level — so even a validation-passing
   request from the frontend would have failed *again* between Laravel and
   the ai-engine. Fixed `StartBacktest::handle()` to build the flat payload
   the ai-engine actually accepts. New `tests/Feature/BacktestCreationTest.php`
   asserts both layers: the exact frontend payload shape is accepted (202),
   and `Http::assertSent()` confirms what actually goes out to
   `/internal/v1/backtests` matches the ai-engine's strict schema. Verified
   live: `POST /api/v1/backtests` → `202 Accepted` (was `422`).
2. **Strategy Analysis's "View Trade Proposal" always said no proposal
   exists, even when one did.** Root cause was upstream of the matching
   logic that message comes from: `services/strategies.ts`'s
   `fetchOptionOpportunities()` called `GET /option-opportunities`, a route
   that was **never implemented** — `StrategyController`'s own doc comment
   says so explicitly ("there is no separate opportunities table... See
   StrategyController"). In live mode this 404's and falls back to
   fixture data (`withFixtureFallback`, working as designed), so every
   "opportunity" a user could click into — from the Options Scanner, or
   from Strategy Analysis's own no-id default selection — carried a
   **fixture-fabricated id**, which could never match a real
   `TradeProposal.strategy_id` in the database no matter how many real
   proposals existed. `StrategyResource`'s fields already match the
   frontend's `Strategy` type exactly, so this was purely a wrong URL.
   Repointed `fetchOptionOpportunities()` to the real `GET /strategies`.
   Verified live: Options Scanner now shows real, previously-generated
   strategies (multiple real NVDA/SPY/TSLA rows from this session's earlier
   agent runs, with real elapsed-time "freshness" values) instead of the
   static 3-row fixture set. (The scanner's richer filters — DTE, min
   confidence, liquidity, spread — aren't enforced server-side by
   `StrategyController::index()`, which only supports `symbol`/
   `agent_run_id`/`selected`; they're accepted as harmless unused query
   params, same as before this fix when the whole list was fixture-only.)
3. **Saving a risk policy always 422'd.** `RiskPolicyLimits` (frontend type)
   used invented `_equity`-suffixed names for 5 of its 10 fields
   (`max_daily_loss_pct_equity` vs. the backend's `max_daily_loss_pct`, etc.)
   and included 4 fields (`max_loss_per_trade_pct_equity`, `min_volume`,
   `min_dte`, `max_dte`) that don't exist anywhere in
   `UpdateRiskPolicyRequest`'s rules, the seeded policy, or any risk
   evaluation logic. Since 5 of the backend's 10 *required* `limits.*`
   fields were consequently never sent under their real names, every save
   failed with 5 "field is required" errors. Also fixed a **scale
   mismatch** hiding behind the same rename: the backend's percentage limits
   are whole numbers (seeded `max_daily_loss_pct: 3.0` = 3%, matching how
   `RiskRuleResult.observed_value` displays elsewhere), but the frontend's
   old defaults were fractional (`0.03`) — silently loosening every policy
   saved from those defaults by 100x, not just failing to save. Renamed the
   type, the form's defaults, `LIMIT_LABELS`, `validate()`, and the fixture
   to the real 10-field, whole-percentage shape (confirmed against the
   *already-passing* `tests/Feature/RiskPolicyVersioningTest.php`, which
   had been using the correct names all along — the backend was never
   wrong here, only the frontend). Verified live: `PATCH /api/v1/risk/policy`
   → `201 Created` (was `422`), toast "Risk policy updated to version 3."
4. **Dashboard widget buttons "doing nothing"** was already fixed in the
   interactive-UX pass earlier in this document (Approve/Execute
   Now/View Details all call real endpoints) — re-confirmed live in this
   pass, no further changes needed. If this is still seen, it's most likely
   a stale (pre-rebuild) frontend container image.
5. **Backtest "not showing results"** was flagged as a real, separate,
   larger gap in this pass and deliberately left unbuilt (`AiEngineClient
   ::getBacktest()` existed but nothing called it). **Update: built in a
   follow-up pass, see "Backtest results pipeline" below** — it's no
   longer a gap.

**Verification performed:** Backend — 50/50 PHPUnit tests passing (47
existing + 3 new), run via the portable PHP under `.tools/` since Docker
was intermittently unreachable during this pass; Pint clean on every
touched file. Frontend — `npm run typecheck` (0 errors), `npm run
test:unit` (39/39, unchanged), `npm run build` (succeeds). Rebuilt all 7
Docker images and re-verified live end-to-end: backtest submission (202),
Options Scanner showing real strategies, and a risk policy save (201) all
confirmed working in a real browser session against the containerized
stack, not just via curl or unit tests.

## Backtest results pipeline — completed

The gap flagged above (a backtest could start but never show results) is
now closed end-to-end.

**What was missing, precisely:** `AiEngineClient::getBacktest()` existed
but nothing ever called it, so `BacktestRun.status` never left `pending`
and no `BacktestResult`/`BacktestTrade` rows were ever created.
`backtest_trades` also had no `symbol` column despite the frontend type and
the ai-engine's own trade payload both carrying one. And two more
mismatches surfaced while wiring this up: `BacktestRunResource` returned
`results` (plural, an array — the relation name) where the frontend's
`BacktestDetail.result` expects a singular object or `null`; and
`BacktestResultResource`/`BacktestTrade` frontend types expect
`drawdown_curve`, `return_distribution`, and `monthly_returns`, none of
which the ai-engine computes directly (it only returns `equity_curve`,
metrics, and a flat trade list).

**Migration** (`add_symbol_to_backtest_trades_and_result_curves_to_backtest_results`):
adds `backtest_trades.symbol` (nullable string), and `backtest_results
.drawdown_curve` / `.return_distribution` / `.monthly_returns` (nullable
jsonb) alongside the existing `metrics`/`equity_curve` columns.

**Backend:** `App\Jobs\StartBacktest` now starts the ai-engine run, then
polls `GET /internal/v1/backtests/{id}` in a bounded loop (15 attempts,
500ms apart — the engine is documented as sub-second for this fixture-scale
build) until it reports `completed`/`failed`, updating `BacktestRun.status`
at each transition (`pending` → `running` → `completed`/`failed`). A new
`App\Services\Backtesting\BacktestResultMapper` does the actual mapping and
persistence, including deriving the three missing chart views from what
the engine *does* return: the drawdown curve is the running peak-to-current
% off the equity curve (mirroring the engine's own `_max_drawdown()`
logic); the return distribution buckets each trade's raw P&L into fixed
dollar ranges; monthly returns groups the equity curve by calendar month.
One correctness detail worth being explicit about: the engine's trades (and
therefore `equity_curve`) are produced per-symbol-then-day, not globally
chronological across symbols in a multi-symbol backtest, so the
reconstructed `{t, equity}` series is sorted by date before being stored —
otherwise a multi-symbol equity chart would visibly jump backward in time
every time the engine moved on to the next symbol. `BacktestRunResource`
now returns a singular `result` (or `null`), matching the frontend type.
`GET /api/v1/backtests/{id}` (already existed, `BacktestController::show()`)
serves the persisted result — no new endpoint was needed, it was already
correctly shaped once the resource layer was fixed.

**Frontend:** `stores/backtests.ts`'s `start()` now polls `GET
/backtests/{id}` every 1.5s until status leaves `pending`/`running` — the
same pattern `stores/agents.ts` already uses for agent runs (the queue
worker processes `StartBacktest` asynchronously in the real deployed stack,
so the run is genuinely still in flight when `start()`'s own POST returns;
it only happens to finish inline under the test suite's
`QUEUE_CONNECTION=sync`). `BacktestingPage.vue` shows a distinct "Running
backtest…" panel while polling with no result yet, and a retry-capable
error panel if the run reports `failed`.

**New tests** (`tests/Feature/BacktestCreationTest.php`, 6 total): the
existing payload-shape/validation tests now fake a completing ai-engine
response so they don't hang for the full poll budget; new tests cover a
full run persisting the correct `BacktestResult`/`BacktestTrade` rows and
status, a failed ai-engine response marking the run `failed` with no rows
created, and `GET /backtests/{id}` returning the persisted `result`
(singular) and `trades` correctly.

**Verified live** against the rebuilt Docker stack: submitted a real
backtest, watched it reach `completed` within a few seconds, and confirmed
all four result sections render with real data — 70% win rate, 10 real
`SPY` trades each with a populated `symbol` column and real entry/exit
dates and P&L, plus working Equity Curve, Drawdown, Return Distribution,
and Monthly Returns charts (checked via actual `<canvas>` elements with
real rendered content, not just the surrounding markup). Backend: 53/53
PHPUnit tests passing (50 + 3 new), Pint clean. Frontend: typecheck clean,
39/39 unit tests, build succeeds.

**Known remaining gap, unrelated to this fix and left alone per this pass's
scope:** `BacktestRun`'s `provenance`/`user_id`/`progress_pct` fields (used
by the frontend's provenance badge — "Simulated fixture data..." — and a
couple of other decorative fields) are never populated by
`BacktestRunResource`, even though the ai-engine's result does carry a
`data_source` field that could back it. Falls back to `'--'` gracefully
(confirmed live, no crash), not something this pass's scope covered.

### Verification performed

`npm run typecheck` (0 errors), `npm run test:unit` (39/39 passing,
unchanged pass count — no existing test asserted the old, wrong `run_id`/body-field
contracts), `npm run build` (succeeds). Rebuilt the `laravel` and `frontend`
Docker images and live-drove all five pages against the actual running
stack (`http://localhost:8080`) with the Claude Browser tool: approved and
executed a real `NVDA` proposal from the Dashboard end to end (201 Created);
ran and cancelled a real analysis pipeline from AI Agents, watching the
5-step visualization track real `pending -> running -> cancelled` polls;
ran a market scan that degraded gracefully instead of erroring; closed a
position and expanded its P&L curve on Portfolio (confirmed the chart's
`<canvas>` actually rendered, not just the surrounding markup); and
approved one and rejected another real pending proposal from Risk
Management's new section, with the typed rejection reason persisted and
visible in the existing Rejected Proposals panel.

## What is honestly not done

- No live Alpaca paper account was connected or tested against in this
  session (no keys were provided/available) — everything above that
  mentions Alpaca was verified against the `demo` adapter and the installed
  `alpaca-py`/`alpaca-mcp-server` package schemas, not a live paper account.
- Docker Compose was never actually built/run in this session (see the
  environment table above — no admin rights to start the Docker daemon).
  `compose.yml` and the Dockerfiles are reviewed by eye and `docker compose
  config` validates the YAML/interpolation, but `docker compose up --build`
  itself has not been exercised. Everything else in this document — the
  ai-engine, the Laravel backend, the frontend, and the cross-service
  integration test above — was verified running directly (portable PHP,
  a Python venv, and Node), not inside containers.
- Playwright E2E tests, a generated OpenAPI spec, and an automated
  frontend/backend contract-drift test are not yet written. The manual
  integration test above (`scripts/smoke_integration.py`) is real but
  covers two scenarios (approved, rejected), run once each, by hand.
- Real-time updates are poll-based, not push: Laravel persists ai-engine
  events but does not yet broadcast them over Reverb/WebSocket, and the
  frontend fetches on load/interval rather than holding a live socket.
- A genuine three-service, browser-to-database round trip (frontend <->
  Laravel <-> ai-engine, all live) was **not** performed as a single chain
  in this session — the two adjacent pairs (Laravel<->ai-engine, and
  frontend<->Laravel) were each verified live and separately, but not
  simultaneously in one browser session watching an ai-engine-triggered
  run update the Dashboard in real time.
- `order.submitted`'s `Order.status` is hard-coded to `New` in
  `EventsController::applyOrderSubmitted` regardless of what the ai-engine
  reports (see the integration section above).
- `docs/hackathon-writeup.md`, `docs/JUDGING_EVIDENCE.md`,
  `docs/demo-script.md`, `docs/SUBMISSION_CHECKLIST.md`,
  `docs/deployment-aws.md` — see those files directly for what's covered;
  none claim a live deployment happened, because one did not.
