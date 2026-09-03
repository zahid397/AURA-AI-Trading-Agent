# Judging Evidence Map

Maps every hackathon requirement to the exact code, UI, endpoint, test, and
demo step that proves it — so a judge (or a teammate) doesn't have to take
any claim on faith.

| Requirement | Code path | UI page | API endpoint | Test | Demo step |
|---|---|---|---|---|---|
| Autonomous AI agents use Alpaca's Trading API | `ai-engine/app/alpaca/trading_api.py` (`AlpacaApiAdapter`, direct `alpaca-py` SDK) | Settings > Alpaca Connection, System Health matrix | `GET /api/v1/system/capabilities`, `POST /internal/v1/capabilities` | `ai-engine/tests/test_trading_graph.py` | Trigger a run; the audit trail shows `submitted_via: alpaca_api` (or `demo` when no keys are configured). |
| Official Alpaca MCP Server or CLI used as a real agent tool path | `ai-engine/app/alpaca/mcp_client.py` (discovers tools via MCP `list_tools`, maps to internal capabilities), `ai-engine/app/alpaca/cli_client.py` + `scripts/alpaca-cli-smoke.sh` | Settings > System Health (MCP connected + tool count, CLI installed + version) | `GET /internal/v1/capabilities` | `ai-engine/tests/test_cli_client.py` | Run `scripts/alpaca-cli-smoke.sh`; open Settings > System Health and show the capability matrix. |
| Every executable strategy uses options | `ai-engine/app/strategies/selector.py`, `payoff.py` — only 5 options strategies are ever selectable; there is no equity-only execution path in `app/agents/execution.py` | Strategy Analysis, Options Scanner | `GET /api/v1/strategies/{id}`, `/strategies/{id}/payoff` | `ai-engine/tests/test_payoff.py`, `test_selector.py` | Show a strategy's exact legs on the Strategy Analysis page. |
| All trading occurs in the Alpaca paper environment | `TradingSafetyServiceProvider` (Laravel), `app/core/config.py` `Settings` validators (FastAPI), hard-coded `paper=True` in `AlpacaApiAdapter` | Persistent "Paper Trading" badge in the top bar (every page) | N/A — enforced at boot, not a toggle | `ai-engine/tests/test_config_guard.py` | Point out the badge; attempt to set `TRADING_ENV=live` locally and show the app refuses to boot. |
| Fresh, dedicated paper account for judging | N/A (operational requirement) | Settings > Alpaca Connection ("fresh hackathon account" checklist) | — | — | See `docs/SUBMISSION_CHECKLIST.md`. |
| Clear, testable thesis for every trade | `ai-engine/app/agents/market_analyst.py` (`MarketThesis` with `evidence`, `invalidation_conditions`) | Strategy Analysis (AI Explanation sections, evidence cards with timestamps) | `GET /api/v1/decisions/{id}`, `/decisions/{id}/trace` | `ai-engine/tests/test_trading_graph.py` (asserts a thesis is generated per run) | Open a decision's trace and read the evidence list against the stated direction/confidence. |
| Evidence of MCP/API use, agent reasoning, risk checks, order submission, portfolio updates via auditable run details | `AuditEvent` accumulation in `GraphState.audit_events`, flushed to Laravel via `app/services/laravel_events.py`, persisted into `agent_events`/`agent_logs` | AI Decision History (timeline grouped by `run_id`) | `GET /api/v1/decisions`, internal `POST /api/internal/v1/events` | `ai-engine` HTTP smoke test in `docs/IMPLEMENTATION_STATUS.md` | Expand a run's timeline: thesis -> strategy -> risk -> (approval) -> order -> portfolio. |
| Build-in-public checklist | — | — | — | — | See `docs/SUBMISSION_CHECKLIST.md`. |

## Deterministic risk gates an LLM cannot bypass

`ai-engine/app/risk/engine.py::evaluate` is a plain function: it takes
already-computed inputs and returns a fixed, fully-populated list of rule
results. No LLM call happens inside it, and no agent has a code path that
calls it with parameters it can freely choose — `policy` comes from
Laravel's versioned `risk_policies` table, `confidence` comes from the
Market Analyst's already-generated thesis, and every other input is either a
live account/quote fact or the strategy's own deterministic payoff math.
Evidence: `ai-engine/tests/test_trading_graph.py::test_impossible_policy_rejects_without_crashing`
sets `min_confidence=0.999` and confirms every candidate is rejected
regardless of what the LLM said.

## Explainability without exposing chain-of-thought

The LLM (`app/llm/provider.py`) is only ever asked to narrate
already-computed, already-labeled facts into prose (see the prompts built in
`app/agents/market_analyst.py::analyze` and `app/agents/risk_guardian.py::review`)
— it never originates a number, and its output is stored as a `str` field
(`MarketThesis.thesis`, not a hidden reasoning trace). The AI Decision
History page's JSON export contains this structured evidence + narration,
never a raw model chain-of-thought.
