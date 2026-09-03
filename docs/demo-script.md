# Demo Script (3–5 minutes)

Assumes `make bootstrap && make up && make demo-reset` has been run (or the
local-dev equivalents in the root README if Docker isn't available on the
demo machine), and you're logged in as the seeded demo user.

1. **Fresh paper connection and system health** — open **Settings > System
   Health**. Point out the capability matrix (Trading API, Market Data,
   Options level, MCP, CLI, paper-verified, last sync) and the persistent
   "Paper Trading · Demo Data" badge in the top bar. If a real paper account
   is connected, show **Settings > Alpaca Connection** with the masked key
   state and the "fresh hackathon account" checklist.

2. **All agents, trigger a run** — go to **AI Agents**. Point out the five
   cards (Market Analyst, Options Strategy, Risk Guardian, Execution,
   Learning) and the workflow map. Click **Run Analysis**. Watch the map
   animate as the run progresses through nodes in real time.

3. **Market evidence and an options candidate** — once the run lands on a
   symbol, open **Options Scanner** (or follow the "Latest AI Decision" card
   from the Dashboard) and select the result. Show the ranked opportunities
   and the confidence/liquidity/return-on-risk breakdown.

4. **Strategy payoff and explainable thesis** — open **Strategy Analysis**
   for that candidate. Walk through the payoff diagram (breakeven, max
   profit/loss markers), the exact legs table, and the AI Explanation
   sections — each claim is backed by an evidence card with a timestamp, not
   free-floating prose.

5. **Deterministic risk approval** — open **Risk Management** or the risk
   panel on the proposal itself. Show the full rule-by-rule pass/fail list
   (not just a single verdict) — this is the same list whether the trade
   gets approved or rejected.

6. **Paper order submission via Alpaca integration** — go to **Trade
   Execution**. If the account is in `approval_required` mode, click
   **Execute Trade** and confirm in the dialog; if `paper_autonomous`, show
   the already-completed order chain instead and explain the mode
   difference. Point out `submitted_via` in the order detail — it will say
   `alpaca_api`, `alpaca_mcp`, or `demo` depending on what's actually
   connected, never a guess.

7. **Order/portfolio update and decision trace** — go to **AI Decision
   History**, find the run, and expand its timeline: thesis generated ->
   strategy proposed -> risk evaluated -> (approval requested/granted) ->
   order submitted -> position updated. Export the sanitized JSON trace to
   show there's nothing hidden.

8. **Backtesting and learning** — open **Backtesting**, run (or show a
   previously completed) backtest, and point out the KPI row and the
   explicit `simulated_fixture` data-source label. Then show a Learning
   Agent recommendation (if one exists in the seeded data) and point out
   that it requires manual review/activation — it never silently changes a
   live risk limit.

Throughout: the amber "Paper trading only — educational demonstration, not
investment advice" disclaimer is visible on every page; nothing shown is a
live trade.
