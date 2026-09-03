# Security

## Paper-only, enforced in code

- **Laravel**: `App\Providers\TradingSafetyServiceProvider` throws before
  the application finishes booting if `config('aura.trading_env')` is not
  `paper`/`demo`, `allow_live_trading` is truthy, or the configured Alpaca
  base URL doesn't contain `paper-api`.
- **FastAPI**: `app/core/config.py`'s `Settings` runs the same three checks
  as Pydantic field validators — constructing `Settings()` at import time
  means a bad config fails at process startup, not on the first trade.
- **Alpaca API adapter**: `AlpacaApiAdapter` hard-codes `paper=True` as a
  Python literal to `TradingClient(...)` — there is no code path in this
  adapter that can construct a live-trading client, regardless of what any
  environment variable says.
- **CLI adapter**: refuses to even construct if `ALPACA_LIVE_TRADE` is
  truthy in the parent environment, and explicitly forces it to `false` in
  the child process environment regardless.
- **Persistent UI badge**: the frontend app shell shows a permanent
  "Paper Trading · Demo Data"-style indicator and an amber disclaimer bar;
  neither can be hidden by any in-app setting.

## Secrets

- Alpaca/LLM API keys live only in `backend/.env` / `ai-engine/.env` (never
  committed — both are gitignored) or container environment variables in a
  real deployment. `AlpacaConnection` (Laravel model) never serializes a raw
  secret in any API Resource — only a masked fingerprint and a boolean
  "configured" flag.
- The internal Laravel<->FastAPI signing secret
  (`AURA_INTERNAL_SIGNING_SECRET` / `INTERNAL_SIGNING_SECRET`) must match on
  both sides; it authenticates service-to-service calls, not end users.
- `app/alpaca/trading_api.py`'s `_sanitize()` strips `api_key`/`secret_key`/
  `Authorization` keys from any raw broker response before it's persisted or
  logged.

## Signed internal API

Both directions of the Laravel<->FastAPI internal API
(`docs/architecture.md`) are authenticated with HMAC-SHA256 over the exact
raw request body plus a timestamp header:

- `message = timestamp + "." + raw_body_bytes`
- `signature = "sha256=" + HMAC_SHA256(shared_secret, message)`
- Requests older than 5 minutes (`internal_timestamp_skew_seconds`) are
  rejected regardless of signature validity.
- Laravel de-duplicates inbound events by `event_id` — a replayed valid
  signature with a previously-seen `event_id` is a no-op, not a re-applied
  event.

## Idempotency and duplicate-order prevention

- Every order is keyed by a deterministic `client_order_id` derived from the
  proposal id (`app/agents/execution.py::build_idempotency_key`) — the same
  proposal always produces the same key.
- `execute()` always calls `get_order_by_client_id` before ever calling
  `submit_option_order`; a match short-circuits to a "replay" result instead
  of submitting again.
- Laravel's `trade_proposals`/`orders` tables enforce uniqueness at the
  database level (unique `client_order_id`, unique broker order id) as a
  second, independent guard.

## Untrusted input

- Market news/text content is never interpolated into a prompt as an
  instruction — the Market Analyst's LLM prompt only ever contains
  already-computed, labeled evidence lines and an explicit "do not invent
  numbers" instruction.
- The Alpaca CLI wrapper never interpolates caller-supplied strings into a
  shell command: it uses `asyncio.create_subprocess_exec` with a fixed
  binary name and an argv list drawn from a hard-coded subcommand allowlist,
  never `shell=True` or string concatenation.

## What is not yet implemented

- Secret rotation tooling (documented as a manual `.env` update + restart
  for this hackathon scope).
- Rate limiting is only applied to the login endpoint
  (`throttle:login`) — broader per-route limits are a follow-up.
- No WAF/DDoS layer — out of scope for a hackathon demo host (see
  `docs/deployment-aws.md`).
