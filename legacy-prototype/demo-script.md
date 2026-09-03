# Demo Script (for what's actually built — see IMPLEMENTATION_STATUS.md)

1. `cd backend && python3 app.py`, open http://localhost:5001 — dashboard
   loads with a seeded portfolio (3 open positions, 3 closed trades). Point out
   the green "Paper Trading · Demo Data" badge and the amber disclaimer bar —
   both are real, persistent UI elements, not just claims in this doc.
2. Click **Run Agents Now**. This calls `POST /api/run`, which executes the
   real pipeline: synthetic price series → SMA/RSI/volatility → strategy
   selection → Black-Scholes leg pricing → deterministic risk check → mock
   order submission → learning stats. The "Latest AI Decision" card updates
   with the actual computed thesis, confidence, legs, and risk verdict.
3. Go to **AI Agents**, pick a different symbol, run it again. Watch the "Last
   Run Detail" panel show all 5 stages of that specific run, including the
   exact risk-gate reasons (approved or rejected, and why).
4. Go to **Positions** — every open position shown here was either seeded or
   created by a real `/api/run` call; each leg's price came from the
   Black-Scholes function, not a hardcoded number.
5. Go to **Performance** — win rate and profit factor are computed live from
   whatever's in the `trades` table, not fixed constants.
6. Back on the Dashboard, click **Pause all agents** in the sidebar. Trigger a
   run again — the Risk Manager stage will reject it with "Global kill switch
   is engaged," and no order will be submitted. Click it again to resume.

Total time: under 3 minutes. Everything above is a real HTTP call to a real
running process — nothing on this list is a static mockup.
