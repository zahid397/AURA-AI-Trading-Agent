# Public API (`/api/v1`, Laravel, Sanctum SPA auth)

All responses use a consistent envelope: `{"data": ..., "meta": {...}}` for
success, `{"error": {"code": "...", "message": "...", "request_id": "..."}}`
for failure (4xx/5xx). Every response carries `X-Request-Id`. Paginated list
endpoints return Laravel's standard `data`/`links`/`meta` cursor or length-aware
pagination shape. Policies gate every mutating action even though there is one
seeded demo user.

## Auth
- `POST /auth/login` `{email,password}` -> sets Sanctum cookie session
- `POST /auth/logout`
- `GET /me` -> current user + settings summary

## Dashboard
- `GET /dashboard/summary` -> KPI row (balance, total P&L, unrealized P&L, buying power, risk level), latest decision summary
- `GET /dashboard/equity-curve?range=1D|1W|1M|3M|1Y|ALL` -> `[{t, equity}]`
- `GET /dashboard/activity` -> recent AI activity timeline entries

## Agents
- `GET /agents` -> the 5 agents with status/metrics
- `GET /agents/{agent}` -> detail: objective, config version, current run, performance trend, recent decisions
- `PATCH /agents/{agent}/state` `{enabled}` -> pause/resume one agent (audited)
- `GET /agents/{agent}/logs?cursor=` -> paginated structured logs
- `POST /agent-runs` `{trigger, symbols[], mode?}` -> queues a run, returns `{run_id}`
- `GET /agent-runs/{run}` -> run detail + node trace
- `POST /agent-runs/{run}/cancel`

## Scanner and strategies
- `POST /option-scans` `{symbols[], filters}` -> queues a scan, returns `{scan_id}` (maps to an agent_run)
- `GET /option-scans/{scan}` -> status + result summary
- `GET /option-opportunities?scan_id=&min_confidence=&strategy=&direction=` -> ranked `strategies` rows
- `GET /strategies/{strategy}` -> full detail incl. legs, evidence, score breakdown
- `GET /strategies/{strategy}/payoff` -> `[{underlying_price, pnl}]` curve + breakevens

## Risk
- `GET /risk/overview` -> risk score, exposure, daily P&L, drawdown, remaining capacity
- `GET /risk/policy` -> active `risk_policies` row (+ version history summary)
- `PATCH /risk/policy` `{limits: {...}}` -> creates a **new version**, never mutates in place (audited)
- `GET /risk/alerts` -> unacknowledged + recent risk alerts
- `POST /risk/kill-switch` `{engaged: bool}` -> global kill switch (audited, broadcasts immediately)

## Portfolio
- `GET /portfolio/summary`
- `GET /portfolio/positions?status=open|closed`
- `GET /portfolio/allocation` -> by underlying/strategy
- `GET /portfolio/performance?period=`

## Proposals, execution, orders
- `GET /trade-proposals?status=`
- `GET /trade-proposals/{proposal}` -> proposal + strategy + risk_evaluation + rule_results
- `POST /trade-proposals/{proposal}/approve` `{notes?}` -> only valid if `pending` and not expired (audited)
- `POST /trade-proposals/{proposal}/reject` `{reason}`
- `POST /trade-proposals/{proposal}/execute` `{idempotency_key}` -> rejects if proposal not approved, quote stale, or risk eval expired
- `GET /orders`, `GET /orders/{order}`
- `POST /orders/{order}/cancel`

## Decisions and backtesting
- `GET /decisions?symbol=&strategy=&agent=&outcome=&date_from=&date_to=`
- `GET /decisions/{decision}`
- `GET /decisions/{decision}/trace` -> full sanitized graph node trace for that run
- `POST /backtests` `{strategy, symbols[], date_range, starting_capital, dte, rules, slippage, risk_policy_version}`
- `GET /backtests/{backtest}`
- `POST /backtests/{backtest}/cancel`
- `GET /backtests/compare?ids=a,b`

## Settings and health
- `GET /settings`, `PATCH /settings`
- `POST /settings/alpaca/test` -> `{status, capabilities}` (never returns the secret)
- `POST /settings/ai-provider/test`
- `GET /system/health` -> Laravel/queue/scheduler/Postgres/Redis reachability
- `GET /system/capabilities` -> proxies `GET /internal/v1/capabilities` from FastAPI (short timeout, cached briefly)

## Internal (not exposed to the browser; separate route file/middleware group, HMAC + service-token auth per docs/architecture.md)
- `backend/routes/internal.php`: `POST /api/internal/v1/events` (FastAPI -> Laravel)
- FastAPI side (`ai-engine`, its own router, same HMAC scheme): `POST /internal/v1/runs`, `GET /internal/v1/runs/{run_id}`, `POST /internal/v1/runs/{run_id}/cancel`, `POST /internal/v1/runs/{run_id}/resume`, `GET /internal/v1/capabilities`, `POST /internal/v1/backtests`, `GET /internal/v1/backtests/{id}`
