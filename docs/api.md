# API Reference

Source of truth: `backend/routes/api.php`. This document is a human-readable
summary of it, not a generated spec — a generated OpenAPI document + a
frontend-type contract-drift test are listed as not-yet-done in
`docs/IMPLEMENTATION_STATUS.md`.

All public routes are under `/api/v1`, Sanctum-cookie-authenticated (except
`POST /api/v1/auth/login`), and return a consistent JSON error envelope on
failure:

```json
{ "error": { "code": "VALIDATION_ERROR", "message": "...", "details": {} }, "request_id": "..." }
```

(see `backend/bootstrap/app.php`'s `withExceptions` block for the exact
mapping from exception type to `code`).

## Auth

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/login` | Rate-limited (`throttle:login`). Sanctum session cookie. |
| POST | `/auth/logout` | |
| GET | `/me` | Current user. |

## Dashboard

| Method | Path |
|---|---|
| GET | `/dashboard/summary` |
| GET | `/dashboard/equity-curve?range=1D\|1W\|1M\|3M\|1Y\|ALL` |
| GET | `/dashboard/activity` |

## Agents

| Method | Path |
|---|---|
| GET | `/agents` |
| GET | `/agents/{agent}` |
| PATCH | `/agents/{agent}/state` |
| GET | `/agents/{agent}/logs` |
| POST | `/agent-runs` |
| GET | `/agent-runs/{agentRun}` |
| POST | `/agent-runs/{agentRun}/cancel` |

## Risk

| Method | Path |
|---|---|
| GET | `/risk/overview` |
| GET | `/risk/policy` |
| PATCH | `/risk/policy` (creates a new version; never mutates an active one) |
| GET | `/risk/alerts` |
| POST | `/risk/kill-switch` |

## Portfolio

| Method | Path |
|---|---|
| GET | `/portfolio/summary` |
| GET | `/portfolio/positions` |
| GET | `/portfolio/allocation` |
| GET | `/portfolio/performance` |

## Trade proposals & orders

| Method | Path |
|---|---|
| GET | `/trade-proposals` |
| GET | `/trade-proposals/{tradeProposal}` |
| POST | `/trade-proposals/{tradeProposal}/approve` |
| POST | `/trade-proposals/{tradeProposal}/reject` |
| POST | `/trade-proposals/{tradeProposal}/execute` (requires an idempotency key; rejects a second execute of an already-executed/expired proposal) |
| GET | `/orders` |
| GET | `/orders/{order}` |
| POST | `/orders/{order}/cancel` |

## Decisions

| Method | Path |
|---|---|
| GET | `/decisions` |
| GET | `/decisions/{decision}` |
| GET | `/decisions/{decision}/trace` |

## Settings & health

| Method | Path |
|---|---|
| GET / PATCH | `/settings` |
| POST | `/settings/alpaca/test` |
| POST | `/settings/ai-provider/test` |
| GET | `/system/health` |
| GET | `/system/capabilities` |

## Internal (service-to-service only — never reachable from the browser)

| Method | Path | Auth |
|---|---|---|
| POST | `/api/internal/v1/events` | HMAC-SHA256 signature (`internal.signature` middleware), not Sanctum |

FastAPI's own internal endpoints (`/internal/v1/runs`, `/internal/v1/capabilities`,
`/internal/v1/backtests`, called by Laravel) are documented in
`docs/architecture.md`'s "Internal API contract" section, not here — they are
never exposed through the public Laravel API at all.
