# AURA AI Trading Agent

**Autonomous AI options trading and risk intelligence platform — paper
trading only.** AURA turns market evidence into explainable, risk-gated
options strategies and executes approved opportunities autonomously in an
Alpaca **paper** account.

> **Paper trading only. Educational demonstration — not investment advice.**
> Live trading is disabled by a hard, code-level guard (see
> [`docs/security.md`](docs/security.md)) — there is no supported
> configuration that enables it.

## Hackathon track

**Options Alpha Agents** (supporting: volatility/event analysis, hedging,
risk protection). See [`docs/JUDGING_EVIDENCE.md`](docs/JUDGING_EVIDENCE.md)
for a requirement-by-requirement map to code/UI/tests, and
[`docs/hackathon-writeup.md`](docs/hackathon-writeup.md) for the full
narrative.

## Architecture

```
Vue 3 SPA  ->  Laravel 13 API (auth, domain, system of record)
                    |  queued job, HMAC-signed
                    v
             FastAPI + LangGraph AI engine  ->  Alpaca paper API / MCP / CLI
                    |  signed idempotent events
                    v
             Laravel (persists, broadcasts)  ->  Postgres + Redis + browser
```

Full diagram and trust boundaries: [`docs/architecture.md`](docs/architecture.md).
LangGraph state machine: [`docs/workflow.md`](docs/workflow.md).

The browser only ever talks to Laravel. It never calls FastAPI, Alpaca, or
an LLM provider directly.

## Feature list

- Five-agent LangGraph workflow (Market Analyst, Options Strategy, Risk
  Guardian, Execution, Learning) with a real human-approval interrupt/resume
  path, not a polling stand-in.
- Deterministic risk engine (14 rules) an LLM cannot edit or bypass.
- Five supported options strategies (Bull Call Spread, Bear Put Spread, Iron
  Condor, Covered Call, Cash-Secured Put) with real payoff/Greeks math.
- `AlpacaGateway` with 4 adapters: demo (offline fixtures), direct API, MCP
  (official `alpaca-mcp-server`), CLI (official `alpaca` binary, diagnostics
  only).
- A real, honestly-labeled backtest engine (no look-ahead, deterministic
  slippage, full trade ledger).
- Ten distinct frontend pages (Dashboard, AI Agents, Options Scanner,
  Strategy Analysis, Risk Management, Portfolio, Trade Execution, Decision
  History, Backtesting, Settings) plus auth.
- Four product modes: `demo`, `observe`, `approval_required`,
  `paper_autonomous` — defaults to `approval_required`, never autonomous
  execution out of the box.

## Stack & versions

| Layer | Stack | Version (as installed in this build) |
|---|---|---|
| Frontend | Vue 3 + TypeScript + Vite + Pinia + Tailwind CSS v4 + ECharts | see `frontend/package.json` / `package-lock.json` |
| Backend API | Laravel 13, PHP 8.3 | Laravel `13.29.0`, PHP `8.3.33` |
| AI Engine | FastAPI + LangGraph, Python 3.12 | FastAPI `0.141.1`, LangGraph `1.2.11`, `alpaca-py` `0.44.0`, Pydantic `2.13.5` |
| Data | PostgreSQL 16, Redis 7 (Docker) / SQLite (local dev fallback) | via `compose.yml` |

## Quick start

### Option A — Docker Compose (target path; written but not build-tested in
this session — see [`docs/IMPLEMENTATION_STATUS.md`](docs/IMPLEMENTATION_STATUS.md))

```bash
cp .env.example .env
make bootstrap
make up
make demo-reset
```

Then open http://localhost:8080.

### Option B — Local dev without Docker (what was actually verified during
this build)

**AI engine:**
```bash
cd ai-engine
python -m venv .venv
.venv/Scripts/python.exe -m pip install fastapi uvicorn "pydantic>=2" pydantic-settings \
  langgraph langgraph-checkpoint-sqlite alpaca-py mcp httpx python-dotenv pytest pytest-asyncio
cp .env.example .env
.venv/Scripts/python.exe -m uvicorn app.main:app --port 8001
```

**Backend** (needs PHP 8.3+/Composer — a portable, no-admin-install option
for Windows is documented in `scripts/bootstrap.sh`):
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan aura:demo-reset   # migrate:fresh + seed the demo scenario
php artisan serve
```

**Frontend:**
```bash
cd frontend
npm ci
npm run dev
```

## Demo login

`demo@aura.trading` / `password` (seeded by `php artisan aura:demo-reset`).
No real credentials are required to explore the app — `demo` mode uses
deterministic, clearly-labeled fixture data throughout.

## Connect a fresh Alpaca paper account

1. Create a **new** paper account/API key at [alpaca.markets](https://alpaca.markets)
   dedicated to this hackathon (see [`docs/SUBMISSION_CHECKLIST.md`](docs/SUBMISSION_CHECKLIST.md)).
2. Put the key/secret in `backend/.env` and `ai-engine/.env` (never commit
   them — both files are gitignored).
3. In the app: **Settings > Alpaca Connection > Test Connection**.
4. Confirm `ALPACA_BASE_URL` still contains `paper-api.alpaca.markets` — the
   app refuses to boot otherwise.

## Running an autonomous paper demo safely

Default mode is `approval_required` — nothing executes without a human
click. Switching to `paper_autonomous` (Settings > Agent Controls) requires
an explicit confirmation dialog explaining the limits and creates an audit
event; it still passes through every deterministic risk gate before any
order is submitted.

## Tests

```bash
# AI engine — verified in this session, 60/60 passing
cd ai-engine && .venv/Scripts/python.exe -m pytest -q

# Backend — see docs/IMPLEMENTATION_STATUS.md / backend/README.md for current status
cd backend && php artisan test

# Frontend — see docs/IMPLEMENTATION_STATUS.md / frontend/README.md for current status
cd frontend && npm run typecheck && npm run test:unit && npm run build
```

## Known limitations

See [`docs/IMPLEMENTATION_STATUS.md`](docs/IMPLEMENTATION_STATUS.md) for the
full, live, honest breakdown. Headlines:

- No live Alpaca paper account was connected during this build — Alpaca
  integration is verified against the demo adapter and the installed SDK's
  real schemas, not a live call.
- Docker Compose is written but not build-tested in this session (no working
  Docker daemon was available in the build sandbox).
- The backtest engine covers 2 of 5 strategies and uses synthetic, not
  historical, price data — labeled as such in its own output.

## Prior prototype

[`legacy-prototype/`](legacy-prototype/) contains this project's first
working version, built in a far more constrained sandbox (no PHP, no Docker,
no package-registry access). Its verified math was ported into `ai-engine/`;
its own README/`IMPLEMENTATION_STATUS.md` explain exactly what it was and
why it existed.

## Disclaimer

Paper trading only. Educational demonstration — not investment advice. Every
number in `demo` mode is simulated and clearly labeled.
