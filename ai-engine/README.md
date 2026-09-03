# AURA AI Engine

FastAPI + LangGraph service that runs the 5-agent trading workflow. Never
called directly by the browser — only by the Laravel backend, over the
signed internal API documented in `../docs/architecture.md`.

## Status (verified in this build session)

60/60 pytest tests passing, `ruff check` clean, and a manual end-to-end HTTP
smoke test against a running `uvicorn` process covering: signed-request auth
(valid + invalid), a full autonomous run to a filled demo order, and a full
`approval_required` interrupt -> resume -> execution round trip. See
`../docs/IMPLEMENTATION_STATUS.md` for the three real bugs found and fixed
while verifying this, and for what's explicitly *not* covered (no live
Alpaca account was available in this session; MCP/CLI adapters are unit
tested against fakes, not a live integration).

## Run locally

```bash
python -m venv .venv
.venv/Scripts/python.exe -m pip install fastapi uvicorn "pydantic>=2" pydantic-settings \
  langgraph langgraph-checkpoint-sqlite alpaca-py mcp httpx python-dotenv pytest pytest-asyncio ruff
cp .env.example .env
.venv/Scripts/python.exe -m uvicorn app.main:app --port 8001
```

`GET /health` and `GET /ready` are unauthenticated. Everything under
`/internal/v1/*` requires an HMAC-signed request (see `app/api/security.py`)
— Laravel is the only intended caller.

## Test / lint

```bash
.venv/Scripts/python.exe -m pytest -q
.venv/Scripts/python.exe -m ruff check app tests
```

## Layout

- `app/workflows/` — `state.py` (the Pydantic state schema every node reads/writes) and `trading_graph.py` (the compiled LangGraph `StateGraph`).
- `app/agents/` — the 5 agents (market_analyst, options_strategy, risk_guardian, execution, learning).
- `app/risk/` — the deterministic risk engine (`engine.py`) and policy limits (`policy.py`) an LLM cannot influence.
- `app/strategies/` — payoff math (`payoff.py`) and the regime-to-strategy selector (`selector.py`).
- `app/indicators/` — technical indicators and Black-Scholes options pricing, both pure/deterministic.
- `app/alpaca/` — `gateway.py` (the shared `Protocol`) + 4 adapters (`demo_adapter.py`, `trading_api.py`, `mcp_client.py`, `cli_client.py`) and `capabilities.py` (the unified capability snapshot).
- `app/backtesting/engine.py` — the honestly-labeled `simulated_fixture` backtest engine.
- `app/monitoring/exit_rules.py` — shared profit-target/stop-loss/time-exit logic used by both live monitoring and the backtester.
- `app/llm/provider.py` — provider-neutral LLM adapter (`FakeLLMProvider` default, `AnthropicProvider` if configured).
- `app/services/` — `laravel_events.py` (signed outbound events) and `run_manager.py` (ties gateway selection + the graph + event delivery together).
- `app/api/` — FastAPI routers and the inbound-signature verification dependency.
