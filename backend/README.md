# AURA AI Trading Agent — Backend

## Backend status

Last verified: `php artisan test` → **36/36 passing, 105 assertions**. `php artisan aura:demo-reset` (drop + migrate + seed) runs cleanly end-to-end on SQLite. All 45 `/api/v1/*` + `/api/internal/v1/events` routes register without error (`php artisan route:list --path=api`).

### Demo login

```
email:    demo@aura.trading
password: password
```

Reset the database to the demo scenario at any time:

```bash
.tools/php/php.exe artisan aura:demo-reset --force
```

This is `migrate:fresh --seed` under the hood (destructive — drops every table first), running `database/seeders/DemoSeeder.php`.

### Implemented

- **Safety rail**: `App\Providers\TradingSafetyServiceProvider` (registered in `bootstrap/providers.php`) throws a `RuntimeException` and aborts boot if `TRADING_ENV` isn't `paper`/`demo`, `ALLOW_LIVE_TRADING` is truthy, or `ALPACA_BASE_URL` looks like the live Alpaca host — verified both by `tests/Unit/TradingSafetyServiceProviderTest.php` (6 cases) and manually against a real process (`TRADING_ENV=live php artisan about` aborts with the guard's exception; unmodified env boots fine).
- **CORS + Sanctum SPA auth**: `config/cors.php` allows `CORS_ALLOWED_ORIGINS` (defaults to the Vite dev origin, `http://localhost:5173`) with credentials; `SANCTUM_STATEFUL_DOMAINS` includes it too. `statefulApi()` in `bootstrap/app.php` already wires Sanctum's `EnsureFrontendRequestsAreStateful` into the `api` middleware group (no manual change needed there — confirmed by reading the framework source).
- **Enums** (`app/Enums/`): all 13 enums that cross the Laravel↔FastAPI internal API boundary, with string values matching `ai-engine/app/workflows/state.py` exactly, plus 6 Laravel-only enums for the remaining enum columns (`AgentType`, `AgentStatus`, `LogLevel`, `PositionStatus`, `BacktestStatus`, `AlpacaEnvironment`, `AlpacaConnectionStatus`).
- **Models** (`app/Models/`): one Eloquent model per migrated table (24 domain models + `User`), with `$fillable`, native enum casts, `decimal:N` casts on every money/price column (never float), `array` casts on JSON columns, and relationships inferred from FK columns. `AlpacaConnection::secret_ciphertext` is in `$hidden` as a hard backstop; `AlpacaConnectionResource` only ever exposes `api_key_last4` + a `configured` boolean.
- **Auth**: `POST /api/v1/auth/login` (session-based via Sanctum, rate-limited 5/min per email+IP), `POST /api/v1/auth/logout`, `GET /api/v1/me`. Demo user seeded with `UserSettings.mode = approval_required` (never defaults to autonomous trading).
- **Demo seeder** (`database/seeders/DemoSeeder.php`): starting equity $100,000 → current $100,245.69 (unrealized +$182.34, realized +$63.35), buying power $78,120.45, an active risk policy v1, a pending SPY bull-call-spread proposal at 87% confidence, executed-and-closed NVDA iron-condor and TSLA bear-put-spread scenarios (2 closed trades), 2 open positions (AAPL, MSFT), 14 days of portfolio snapshots, and a completed backtest run + result. All option expirations are relative to `now()` (never already expired).
- **API v1 controllers** (`app/Http/Controllers/Api/V1/`): Dashboard, Agents, AgentRuns, Risk (policy versioning — `PATCH /risk/policy` always inserts a new version row and flips `active` inside a transaction, never mutates the old row; verified by `RiskPolicyVersioningTest`), Portfolio, TradeProposals (approve/reject/execute; `execute` requires an `Idempotency-Key` header, replaying a key returns the original order with no duplicate, executing an already-executed/expired proposal with a new key is rejected — verified by `TradeProposalExecutionTest`), Orders, Decisions, Settings (Alpaca/AI-provider connectivity tests — never logs or returns a raw secret; the Alpaca test now catches every `\Throwable`, not just `ConnectionException`, so a stray exception's stack trace can never carry the secret to the log), System (health/capabilities), Strategies (read-only "scan results" view over `strategies` — there's no separate `option_scans` table in the schema, so this doubles as the opportunities view), Backtests (list/start/show, backed by `backtest_runs`/`backtest_results`/`backtest_trades`).
- **JSON error envelope**: every error response is `{"error": {"code", "message", "details?"}, "request_id"}`; `App\Http\Middleware\AddRequestId` generates/propagates `X-Request-Id` on every request.
- **Internal API** (`app/Services/AiEngine/AiEngineClient.php`, `app/Http/Controllers/Internal/V1/EventsController.php`): HMAC-SHA256 signing (`X-Aura-Timestamp` + `X-Aura-Signature` headers, Stripe-webhook-style `hmac(secret, "{timestamp}.{raw body}")`), 5-minute skew tolerance, `event_id` idempotency (replays are 200 no-ops), per-event-type payload validation, persistence in a DB transaction. Verified by `InternalEventsTest` (valid signature accepted, bad signature/missing headers/stale timestamp rejected, replay is a no-op, unknown `run_id` rejected, route works with no Sanctum session). Broadcasting to the frontend is **not** wired to Reverb yet — a `AgentEventReceived` Laravel event fires (best-effort) as a hook point for later; the frontend should poll in the meantime.

### Known gaps / simplifications (be aware before building on these)

- **No Reverb/WebSocket broadcasting.** Events are persisted and a local event fires, but nothing pushes to the frontend in real time yet.
- **Trade execution never calls a real broker.** `TradeProposalController::execute()` creates the `Order`/`OrderLeg` rows directly with `submitted_via = demo` rather than routing through the ai-engine's `AlpacaGateway` — there's no live ai-engine process in this sandbox to round-trip through. The idempotency contract is fully real and tested; the "channel" is a documented simplification.
- **`AiEngineClient` calls a FastAPI service that isn't running in this sandbox.** `POST /agent-runs`, `POST /backtests`, and the settings/system capability checks will report `AI_ENGINE_UNREACHABLE` until the real `ai-engine/` service is up at `AI_ENGINE_BASE_URL`. This is expected and handled gracefully (jobs log a warning and leave the run as `pending`; the HTTP endpoints return a reachable:false payload instead of a 500).
- **Alpaca connectivity is untested against a real account** (no real paper-trading credentials in this environment). The code path is real (signs an actual request to `ALPACA_BASE_URL`/v2/account) but has only been exercised against unreachable/placeholder credentials.
- **Per-event-type payload schemas in `EventsController`** are Laravel's best guess at a reasonable contract (documented inline) — the ai-engine's Pydantic schemas are the source of truth and were still being finalized in parallel; expect to reconcile field names once both sides are running together.
- **Rate limiting** is only configured for `POST /auth/login`. Other write endpoints have no throttle yet.
- **No admin/RBAC.** Every protected resource is scoped to `request->user()->id` ownership; there's no multi-user/team/role model.
- Postgres-specific migration behavior (the `WHERE active = true` partial unique indexes on `risk_policies`/`trade_proposals`) has only been exercised on SQLite here — it's written to be Postgres-compatible standard SQL, but hasn't run against a real Postgres instance in this sandbox.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
