# Database Schema (PostgreSQL, owned by Laravel)

Conventions: UUID primary keys (`ulid()`/`uuid()` per Laravel 13 migration
helpers) except Laravel's own auth scaffolding tables which keep bigint ids;
all money/price columns are `decimal(18,4)`; all timestamps are `timestamptz`
stored in UTC; every table has `created_at`/`updated_at`; soft deletes only
where noted.

| Table | Key columns | Notes |
|---|---|---|
| `users` | id (uuid), name, email unique, password, timezone default `UTC`, currency_display default `USD`, default_page | Laravel auth base |
| `alpaca_connections` | id, user_id fk, environment enum(`paper`,`demo`) check constraint, account_id nullable, api_key_last4, secret_ciphertext (Laravel `encrypted` cast, never selected by default), status enum(`not_configured`,`connected`,`error`), capabilities jsonb, last_synced_at | Never returns plaintext key/secret via API |
| `user_settings` | id, user_id fk unique, mode enum(`demo`,`observe`,`approval_required`,`paper_autonomous`) default `demo`, confidence_threshold decimal, notification_prefs jsonb | One row per user |
| `agents` | id, type enum(`market_analyst`,`options_strategy`,`risk_guardian`,`execution`,`learning`) unique, enabled bool default true, status enum(`idle`,`running`,`paused`,`error`), config_version int default 1 | Seeded with the 5 agents |
| `agent_runs` | id (uuid), user_id fk, trigger enum(`manual`,`scheduled`,`approval_resume`), mode enum (same as user_settings), status enum(`pending`,`running`,`awaiting_approval`,`completed`,`failed`,`cancelled`,`rejected`), trace_id uuid, started_at, completed_at, summary text nullable, symbol_universe jsonb | `id` doubles as the LangGraph `run_id` shared with FastAPI |
| `agent_logs` | id, agent_run_id fk, agent_type, level enum(`debug`,`info`,`warn`,`error`), message text, context jsonb (redacted before insert), created_at | Paginated on Agents page |
| `market_snapshots` | id, symbol, snapshot_at, price decimal, volume bigint, indicators jsonb | One row per symbol per run |
| `strategies` | id, agent_run_id fk, symbol, strategy_type enum(5 supported types), regime enum(`bullish`,`bearish`,`neutral`), thesis text, confidence decimal, net_debit decimal nullable, net_credit decimal nullable, max_profit decimal, max_loss decimal, breakevens jsonb, probability_of_profit decimal, liquidity_score decimal, score decimal, score_breakdown jsonb, quote_timestamp timestamptz, dte int, rank int nullable, selected bool default false | |
| `strategy_legs` | id, strategy_id fk, action enum(`buy`,`sell`), occ_symbol, underlying, option_type enum(`call`,`put`), strike decimal, expiration date, position_intent enum(4 values), ratio_qty int, quote_bid decimal, quote_ask decimal, delta/gamma/theta/vega decimal nullable, open_interest int nullable, volume int nullable, quote_time timestamptz | |
| `ai_decisions` | id, agent_run_id fk, strategy_id fk nullable, thesis text, evidence jsonb, confidence decimal, model_provider, model_name, prompt_version, feature_snapshot jsonb, created_at | Immutable once written |
| `risk_policies` | id, version int unique, name, limits jsonb (see below), active bool, created_by fk nullable | New version row per edit; never mutate a published version |
| `trade_proposals` | id (uuid), agent_run_id fk, strategy_id fk, user_id fk, status enum(`pending`,`approved`,`rejected`,`expired`,`executed`), risk_evaluation_id fk nullable, expires_at, approved_at, approved_by fk nullable, rejected_reason text nullable | Partial unique index: at most one row with `status='approved'` per `strategy_id` |
| `risk_evaluations` | id (uuid), trade_proposal_id fk, risk_policy_id fk, risk_policy_version int (denormalized, immutable snapshot), result enum(`approved`,`approval_required`,`rejected`), risk_score decimal, evaluated_at | |
| `risk_rule_results` | id, risk_evaluation_id fk, rule_key, description, observed_value text, limit_value text, passed bool, severity enum(`info`,`warning`,`critical`), explanation text | Many rows per evaluation |
| `orders` | id (uuid), trade_proposal_id fk, broker_order_id nullable unique, client_order_id unique, idempotency_key unique, status enum(`new`,`accepted`,`partially_filled`,`filled`,`rejected`,`cancelled`,`expired`,`unknown_reconciling`), order_class, symbol, qty, submitted_via enum(`alpaca_api`,`alpaca_mcp`,`alpaca_cli`,`demo`), raw_request jsonb, raw_response jsonb (secrets stripped), submitted_at | Idempotency enforced at the DB level, not just app code |
| `order_legs` | id, order_id fk, occ_symbol, side, ratio_qty, position_intent, filled_qty, filled_avg_price decimal nullable | |
| `trades` | id, order_id fk, fill_id unique, qty, price decimal, side, filled_at | Fill-level ledger |
| `positions` | id, user_id fk, strategy_id fk nullable, symbol, legs jsonb, quantity, avg_entry_price decimal, current_value decimal, unrealized_pl decimal, status enum(`open`,`closed`), opened_at, closed_at nullable | |
| `portfolio_snapshots` | id, user_id fk, snapshot_at, equity decimal, cash decimal, buying_power decimal, unrealized_pl decimal, realized_pl decimal, options_exposure decimal | EOD + on-demand |
| `performance_reports` | id, user_id fk, period_start, period_end, total_return decimal, win_rate decimal, profit_factor decimal, max_drawdown decimal, sharpe_like decimal nullable, generated_at | |
| `backtest_runs` | id (uuid), user_id fk, config jsonb, seed_hash, status enum(`pending`,`running`,`completed`,`failed`,`cancelled`), started_at, completed_at | |
| `backtest_results` | id, backtest_run_id fk, metrics jsonb, equity_curve jsonb, created_at | |
| `backtest_trades` | id, backtest_run_id fk, entry_at, exit_at, legs jsonb, pnl decimal, exit_reason | Full ledger, avoids one giant jsonb blob |
| `agent_events` | id, event_id uuid unique (idempotency key from FastAPI), agent_run_id fk, type, payload jsonb, signature_valid bool, received_at | Every accepted internal event from FastAPI, replay-safe |
| `notifications` | Laravel's standard notifications table | in-app + email |
| `audit_logs` | id, user_id fk nullable, action, entity_type, entity_id, before jsonb nullable, after jsonb nullable, request_id, ip, created_at | Credential/risk-policy/autonomy/approval/kill-switch changes |

`risk_policies.limits` shape (all keys required, versioned as a whole):
```json
{
  "min_confidence": 0.55,
  "max_loss_per_trade_pct_equity": 0.02,
  "max_position_risk_pct_equity": 0.05,
  "max_total_options_exposure_pct_equity": 0.25,
  "max_symbol_exposure_pct_equity": 0.10,
  "max_open_positions": 8,
  "max_daily_loss_pct_equity": 0.03,
  "max_drawdown_pct_equity": 0.10,
  "min_open_interest": 100,
  "min_volume": 10,
  "max_spread_pct": 0.15,
  "max_quote_age_seconds": 300,
  "min_dte": 7,
  "max_dte": 45
}
```

Standard Laravel/Sanctum tables (`password_reset_tokens`, `sessions`,
`personal_access_tokens`, `jobs`, `failed_jobs`, `cache`) are created by
framework migrations, not listed above.
