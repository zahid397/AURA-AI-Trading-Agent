# Coherent demo scenario (single source of truth for seeders/fixtures)

Every number below must match exactly across Laravel seeders, ai-engine demo
fixtures, and any frontend mock data. Contract dates are relative to a
**seed anchor date** so they never render as already-expired — compute
`expiration = seed_anchor_date + N days`, never a hard-coded calendar date.

- `seed_anchor_date` = the date the seeder runs (`now()`), stored once on the
  seeded `portfolio_snapshots` row so re-running `make demo-reset` regenerates
  consistent, non-expired contracts.
- Starting equity: **$100,000.00**
- Current balance: **$100,245.69**
- Total P&L: **+$245.69**
- Unrealized P&L: **+$182.34**
- Realized P&L: total - unrealized = **+$63.35**
- Buying power: **$78,120.45**
- Risk level: **LOW**
- Demo user: `demo@aura.trade` — seeded, `user_settings.mode = approval_required`

## Seeded decisions / strategies (3, one per symbol)
1. **SPY Bull Call Spread** — regime bullish, confidence **87%**, DTE 30,
   legs: buy call ~1% OTM / sell call ~6% OTM, status `executed` (has a
   filled order + open position).
2. **NVDA** — Iron Condor, regime neutral/high-IV, confidence ~76%, status
   `pending` trade proposal (sitting in Trade Execution awaiting approval —
   this is what demonstrates the `approval_required` pause/resume path).
3. **TSLA Covered Call** — confidence ~81%, status `rejected` by the Risk
   Guardian (use it to populate the Risk Management "rejected proposals"
   section with a real rule failure, e.g. max single-symbol exposure).

## Positions / trades
- 1 open position (from the executed SPY spread).
- At least 2 closed trades with real P&L (one win, one loss) feeding the
  Learning Agent's win-rate/profit-factor numbers and the Performance page —
  these numbers must be *computed* from the trades table, never hard-coded
  constants in a controller.

## Agents (seeded `agents` table)
All 5 (`market_analyst`, `options_strategy`, `risk_guardian`, `execution`,
`learning`) enabled, `status = idle`, `config_version = 1`, with a handful of
`agent_logs` rows per agent from the seeded run so the Agents page isn't an
empty state on first load.

## Risk policy
Seed `risk_policies` version 1 (active) with the limits object from
`docs/db-schema.md`. The TSLA rejection above must cite one of these limits
by name in its `risk_rule_results` row.

## Backtest
Seed one completed `backtest_runs` row (Bull Call Spread on SPY, a plausible
historical-looking window) with `backtest_results` metrics — label it clearly
as `simulated fixture` per the Backtesting page's honesty requirement, since
this build's backtester runs on synthetic/fixture bars, not a licensed
options-history feed.
