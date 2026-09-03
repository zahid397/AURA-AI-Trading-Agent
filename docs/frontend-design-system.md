# Frontend design system & page specs

Product: **AURA AI Trading Agent** — autonomous AI options trading & risk
intelligence platform, paper-trading only. Feel: credible fintech startup
(Stripe clarity + Bloomberg density + Apple restraint), not a generic admin
template.

## Tokens

```css
--brand-50:  #EFF6FF;
--brand-500: #3B82F6;
--brand-600: #2563EB;  /* primary */
--brand-700: #1D4ED8;
--ink-950:   #0F172A;  /* primary text */
--slate-600: #475569;  /* secondary text */
--surface:   #FFFFFF;
--canvas:    #F8FAFC;  /* page background */
--border:    #E2E8F0;
--success:   #10B981;
--danger:    #EF4444;
--warning:   #F59E0B;
--info:      #3B82F6;
```
Font: Inter (system-ui fallback stack). Tabular numerals (`font-variant-numeric: tabular-nums`)
for all financial figures. Icons: `lucide-vue-next` only, no emoji. Card
radius 12–16px. 8px spacing scale. Motion 140–220ms, respect
`prefers-reduced-motion`. Meet WCAG AA contrast; visible focus rings; no
color-only status (always pair with an icon/label).

## Stack decisions for this build
Vue 3 `<script setup>` + TypeScript strict, Vite, Vue Router, Pinia, Tailwind
CSS (configured with the tokens above as theme extensions), `lucide-vue-next`,
Apache ECharts via `vue-echarts`, Axios with a centralized client
(`src/services/api.ts`) that attaches `X-Request-Id`, handles 401 -> redirect
to login, retries GET-only on network failure (max 2, exponential backoff),
and surfaces the error envelope's `code`/`message`. Vitest + Vue Test Utils
for unit tests; Playwright for one happy-path e2e spec.

## App shell
- Left sidebar (collapsible desktop, rail on tablet, drawer on mobile): AURA
  mark + wordmark; nav items Dashboard, AI Agents, Options Scanner, Strategy
  Analysis, Risk Management, Portfolio, Trade Execution, AI Decisions,
  Backtesting, Settings; bottom-of-sidebar system-status card + kill-switch
  button + "Powered by Alpaca — Paper Trading" attribution.
- Top bar: sidebar toggle, breadcrumb/page title, environment badge
  (`DEMO DATA` / `PAPER TRADING` — never anything implying live trading),
  market clock/status, notifications bell, user menu.
- Persistent amber disclaimer strip: "Paper trading only. Educational
  demonstration — not investment advice."
- Every page: skeleton loading state matching final geometry, empty state
  with one clear action, inline retry on fetch error, toasts for transient
  success, confirmation dialogs for execution/rejection/kill-switch/autonomy
  changes.

## Pages (routes under `src/pages/`, each with distinct layout — do not reuse one generic template)

1. **DashboardPage** `/dashboard` — KPI row (Balance, Total P&L, Unrealized
   P&L, Buying Power, Risk Level); large equity curve with range tabs
   (1D/1W/1M/3M/1Y/ALL); Latest AI Decision card; Open Positions table; AI
   Agents Status strip (5 agents); Market Overview tabs; Recent Trades table;
   Performance Overview; AI Activity Timeline; system-health banner when
   degraded.
2. **AgentsPage** `/agents` — header with autonomy mode + Run Analysis / Pause
   All / Kill Switch; top-down workflow map (Market Analyst -> Options
   Strategy -> Risk Guardian -> Execution -> Learning) animated only while a
   run is active; 5 agent cards (status, current task, last heartbeat,
   decisions today, latency); click -> detail panel with logs, config
   version, performance trend; run trace panel highlighting current graph
   node.
3. **OptionsScannerPage** `/options-scanner` — ticker/universe search header;
   sticky filter rail (direction, strategy, DTE, IV percentile, min
   confidence, max risk, min OI/volume, max spread); sentiment/volatility
   summary; scan progress with cancel; ranked results (data grid desktop,
   cards mobile) — symbol, strategy, regime, confidence, entry, expiry, max
   risk/profit, POP, liquidity, freshness; split-view detail panel with legs,
   Greeks, payoff mini-chart, score breakdown.
4. **StrategyAnalysisPage** `/strategy-analysis/:id?` — header with
   strategy/state/quote-timestamp/confidence; main: interactive payoff
   diagram (breakeven, max P/L markers) + scenario table; right rail: entry,
   target, max loss, POP, confidence, DTE, debit/credit, risk/reward; legs
   table; AI Explanation sections (Market Conditions, Technical Signals,
   Volatility, Liquidity, Risk Analysis, Expected Outcome, Invalidation);
   confidence breakdown (deterministic vs. model-judgment components); audit
   ribbon (run id, prompt/config version, model/provider, tools used, risk
   policy version, trace link); actions: Create Trade Proposal, Compare,
   Return to Scanner.
5. **RiskManagementPage** `/risk-management` — `AI Protection: ACTIVE` +
   kill-switch state; portfolio risk gauge; cards for daily loss limit,
   current daily P&L, exposure %, position-sizing budget, drawdown, remaining
   capacity; exposure-by-underlying/strategy chart; drawdown chart with
   threshold line; risk policy table (rule/value/limit/status/severity);
   alerts stream with acknowledge; rejected-proposals section explaining
   which rules blocked each trade; editable policy drawer (versioned,
   confirmation required).
6. **PortfolioPage** `/portfolio` — value/realized/unrealized/buying-power/
   exposure row; equity curve; allocation donut/treemap; holdings table
   (strategy, legs, qty, entry, value, P&L, DTE, Greeks, risk); tabs Open /
   Closed / Orders / Activity; monthly-return heatmap; performance analytics
   (win rate, avg win/loss, profit factor, max drawdown, Sharpe-like only if
   correctly computed and labeled); CSV export; position detail drawer.
7. **TradeExecutionPage** `/trade-execution/:proposalId?` — mode banner;
   stepper Proposal -> Risk Review -> Approval -> Broker Submission ->
   Fill/Monitoring; trade request card; legs + order preview (Alpaca
   `order_class`/`legs` terminology); risk approval panel listing every gate;
   Execute / Reject / Review Analysis actions with confirmation dialog +
   idempotency key; disabled when stale/policy-failed; durable status for
   submitted/accepted/partial/filled/rejected/cancelled/error; autonomous
   trades show the full immutable chain without implying a human clicked
   approve.
8. **DecisionHistoryPage** `/decisions` — filter bar (date, symbol, strategy,
   agent, outcome, confidence, status); vertical timeline grouped by
   `run_id` (Market Analyst -> Options Strategy -> Risk Guardian -> Execution
   -> Learning, each with icon/timestamp/summary/badge/duration/channel);
   expand -> graph node inputs/outputs, risk rules, sanitized tool calls,
   errors/retries, order/portfolio result; export sanitized JSON trace
   (structured evidence + concise rationale, never raw model chain-of-thought).
9. **BacktestingPage** `/backtesting` — test builder (strategy, universe, date
   range, capital, DTE, entry/exit rules, confidence threshold, slippage,
   risk policy); progress + cancel; KPI row (win rate, profit factor, total
   return, max drawdown, trade count, sample period); equity curve, drawdown
   chart, return distribution, monthly heatmap; trades table; strategy
   comparison view; explicit `historical` / `simulated fixture` /
   `insufficient data` labeling.
10. **SettingsPage** `/settings` — sub-nav: General, Alpaca Connection
    (paper-only, masked key state, fresh-hackathon-account checklist),
    AI Provider, Risk Settings (version history), Agent Controls,
    Notifications, Security, System Health (Laravel/queue/scheduler/FastAPI/
    Postgres/Redis/Alpaca/MCP/CLI/provider/realtime). Secret inputs are
    write-only; API only ever returns whether-configured + masked
    fingerprint.

Plus `pages/auth/LoginPage.vue` (polished, seeded demo credentials, no public
marketing site needed).

## API contract
See `docs/api-routes.md` for the exact Laravel endpoints to call — do not
invent endpoints. Build `src/types/api.ts` matching the resource shapes in
`docs/db-schema.md`. If the Laravel backend isn't finished yet, build against
these contracts with a typed mock/dev server (e.g. a `msw` or simple Express
fixture layer under `src/dev/`) so pages are independently verifiable, and
document that the real Laravel wiring still needs to be pointed at
`VITE_API_BASE_URL`.

## Demo scenario numbers (must match backend seeders — see docs/demo-scenario.md)
Starting equity ~$100,000, balance ~$100,245.69, total P&L ~+$245.69,
unrealized P&L ~+$182.34, buying power ~$78,120.45, risk level LOW, a SPY Bull
Call Spread decision at ~87% confidence, plus NVDA and TSLA examples.
