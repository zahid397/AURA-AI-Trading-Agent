"""
agents.py

A simplified, sequential stand-in for the LangGraph multi-agent workflow the
master prompt specifies. `langgraph` could not be installed in this sandbox
(pip install fails -- network egress to PyPI's index is blocked for packages not
already vendored in this image), so this file implements the same five-stage
pipeline as plain, typed Python functions called in order. Each stage is a real,
independently testable function -- nothing here is hardcoded output dressed up
as an "agent". The state passed between stages is logged, so a run can be
replayed/audited, which is the property that actually mattered from the spec.

Pipeline: MarketAnalyst -> OptionsStrategy -> RiskManager -> Execution -> Learning
"""

from __future__ import annotations
import time
import uuid
from dataclasses import dataclass, field

from market_math import synthetic_price_series, sma, rsi, realized_volatility, black_scholes
from mock_alpaca import MockAlpacaAdapter


# ---------------------------------------------------------------------------
# Stage 1: Market Analyst
# ---------------------------------------------------------------------------

def market_analyst(symbol: str) -> dict:
    prices = synthetic_price_series(symbol)
    spot = prices[-1]
    sma20, sma50 = sma(prices, 20), sma(prices, 50)
    r = rsi(prices, 14)
    vol = realized_volatility(prices, 20) or 0.25

    if sma20 and sma50 and sma20 > sma50 and r and r > 55:
        trend = "bullish"
    elif sma20 and sma50 and sma20 < sma50 and r and r < 45:
        trend = "bearish"
    else:
        trend = "neutral"

    vol_regime = "high" if vol > 0.30 else ("low" if vol < 0.15 else "moderate")

    return {
        "symbol": symbol, "spot": spot, "sma20": sma20, "sma50": sma50,
        "rsi": r, "volatility": vol, "vol_regime": vol_regime, "trend": trend,
        "prices_tail": prices[-30:],
    }


# ---------------------------------------------------------------------------
# Stage 2: Options Strategy
# ---------------------------------------------------------------------------

STRATEGY_RULES = {
    ("bullish", "low"): "bull_call_spread",
    ("bullish", "moderate"): "bull_call_spread",
    ("bullish", "high"): "cash_secured_put",
    ("bearish", "low"): "bear_put_spread",
    ("bearish", "moderate"): "bear_put_spread",
    ("bearish", "high"): "bear_put_spread",
    ("neutral", "low"): "covered_call",
    ("neutral", "moderate"): "iron_condor",
    ("neutral", "high"): "iron_condor",
}

STRATEGY_LABELS = {
    "bull_call_spread": "Bull Call Spread",
    "bear_put_spread": "Bear Put Spread",
    "iron_condor": "Iron Condor",
    "covered_call": "Covered Call",
    "cash_secured_put": "Cash Secured Put",
}


def options_strategy(market_view: dict, dte: int = 30) -> dict:
    spot, vol = market_view["spot"], market_view["volatility"]
    strat_key = STRATEGY_RULES[(market_view["trend"], market_view["vol_regime"])]

    def strike(pct_otm):
        return round(spot * (1 + pct_otm), 2)

    legs = []
    if strat_key == "bull_call_spread":
        k1, k2 = strike(0.01), strike(0.06)
        buy = black_scholes(spot, k1, dte, vol, option_type="call")
        sell = black_scholes(spot, k2, dte, vol, option_type="call")
        legs = [
            {"action": "BUY", "qty": 1, "type": "CALL", "strike": k1, "price": buy.price},
            {"action": "SELL", "qty": 1, "type": "CALL", "strike": k2, "price": sell.price},
        ]
        net_debit = round(buy.price - sell.price, 2)
        max_loss, max_gain = net_debit * 100, round((k2 - k1) - net_debit, 2) * 100
        thesis = (f"Bullish trend confirmed by a 20/50 SMA crossover with RSI at "
                  f"{market_view['rsi']}, in a {market_view['vol_regime']}-volatility regime "
                  f"({vol*100:.1f}% annualized). A debit call spread captures upside to the "
                  f"short strike while capping risk to the net debit paid.")
    elif strat_key == "bear_put_spread":
        k1, k2 = strike(-0.01), strike(-0.06)
        buy = black_scholes(spot, k1, dte, vol, option_type="put")
        sell = black_scholes(spot, k2, dte, vol, option_type="put")
        legs = [
            {"action": "BUY", "qty": 1, "type": "PUT", "strike": k1, "price": buy.price},
            {"action": "SELL", "qty": 1, "type": "PUT", "strike": k2, "price": sell.price},
        ]
        net_debit = round(buy.price - sell.price, 2)
        max_loss, max_gain = net_debit * 100, round((k1 - k2) - net_debit, 2) * 100
        thesis = (f"Bearish trend (SMA20 below SMA50, RSI {market_view['rsi']}). A debit put "
                  f"spread captures downside to the short strike with defined, capped risk.")
    elif strat_key == "iron_condor":
        kp1, kp2, kc1, kc2 = strike(-0.08), strike(-0.04), strike(0.04), strike(0.08)
        sp = black_scholes(spot, kp2, dte, vol, option_type="put")
        bp = black_scholes(spot, kp1, dte, vol, option_type="put")
        sc = black_scholes(spot, kc1, dte, vol, option_type="call")
        bc = black_scholes(spot, kc2, dte, vol, option_type="call")
        legs = [
            {"action": "BUY", "qty": 1, "type": "PUT", "strike": kp1, "price": bp.price},
            {"action": "SELL", "qty": 1, "type": "PUT", "strike": kp2, "price": sp.price},
            {"action": "SELL", "qty": 1, "type": "CALL", "strike": kc1, "price": sc.price},
            {"action": "BUY", "qty": 1, "type": "CALL", "strike": kc2, "price": bc.price},
        ]
        net_credit = round((sp.price + sc.price) - (bp.price + bc.price), 2)
        max_gain = net_credit * 100
        max_loss = round(((kp2 - kp1) - net_credit), 2) * 100
        thesis = (f"Range-bound view: RSI {market_view['rsi']} is near neutral and volatility "
                  f"is {market_view['vol_regime']} ({vol*100:.1f}% annualized). An iron condor "
                  f"collects premium if {market_view['symbol']} stays between the short strikes "
                  f"through expiry.")
    elif strat_key == "covered_call":
        k1 = strike(0.05)
        sc = black_scholes(spot, k1, dte, vol, option_type="call")
        legs = [{"action": "SELL", "qty": 1, "type": "CALL", "strike": k1, "price": sc.price}]
        max_gain = round((k1 - spot) * 100 + sc.price * 100, 2)
        max_loss = round(spot * 100 - sc.price * 100, 2)  # simplified: assumes shares held
        thesis = (f"Low-volatility, range-bound tape ({vol*100:.1f}% annualized). Selling a "
                  f"call above spot generates income against an assumed underlying position.")
    else:  # cash_secured_put
        k1 = strike(-0.05)
        sp = black_scholes(spot, k1, dte, vol, option_type="put")
        legs = [{"action": "SELL", "qty": 1, "type": "PUT", "strike": k1, "price": sp.price}]
        max_gain = round(sp.price * 100, 2)
        max_loss = round((k1 * 100) - (sp.price * 100), 2)
        thesis = (f"Bullish bias but elevated volatility ({vol*100:.1f}% annualized) inflates "
                  f"put premium. Selling a cash-secured put earns income with a favorable "
                  f"entry price if assigned.")

    # A simple, transparent composite confidence score -- NOT a trained model's
    # output. It rewards trend clarity (RSI distance from 50) and penalizes
    # extreme volatility (harder to have real edge). Documented here so nobody
    # mistakes it for a calibrated ML probability.
    rsi_strength = abs((market_view["rsi"] or 50) - 50) / 50  # 0..1
    vol_penalty = max(0.0, 1 - abs(vol - 0.20) / 0.20)  # peaks near 20% vol
    confidence = round(min(96, max(35, 50 + rsi_strength * 35 + vol_penalty * 15)), 0)

    return {
        "strategy_key": strat_key,
        "strategy_label": STRATEGY_LABELS[strat_key],
        "symbol": market_view["symbol"],
        "spot": spot,
        "dte": dte,
        "legs": legs,
        "max_loss": abs(max_loss),
        "max_gain": abs(max_gain),
        "thesis": thesis,
        "confidence": confidence,
    }


# ---------------------------------------------------------------------------
# Stage 3: Risk Manager -- deterministic hard gates, cannot be overridden by
# an LLM (there is no LLM in this call path at all, by construction).
# ---------------------------------------------------------------------------

RISK_LIMITS = {
    "min_confidence": 60,
    "max_loss_pct_of_equity": 0.02,     # 2% max risk per trade
    "max_daily_loss_pct": 0.03,         # 3% max daily loss before kill switch
    "max_total_exposure_pct": 0.25,     # 25% of equity across open positions
}


def risk_manager(strategy: dict, account: dict, open_exposure: float, killed: bool) -> dict:
    reasons = []
    approved = True

    if killed:
        approved = False
        reasons.append("Global kill switch is engaged; no new orders permitted.")

    if strategy["confidence"] < RISK_LIMITS["min_confidence"]:
        approved = False
        reasons.append(f"Confidence {strategy['confidence']}% is below the "
                        f"{RISK_LIMITS['min_confidence']}% minimum.")

    equity = account["equity"]
    max_loss_allowed = equity * RISK_LIMITS["max_loss_pct_of_equity"]
    if strategy["max_loss"] > max_loss_allowed:
        approved = False
        reasons.append(f"Max loss ${strategy['max_loss']:.2f} exceeds the per-trade cap "
                        f"of ${max_loss_allowed:.2f} (2% of equity).")

    projected_exposure = open_exposure + strategy["max_loss"]
    max_exposure_allowed = equity * RISK_LIMITS["max_total_exposure_pct"]
    if projected_exposure > max_exposure_allowed:
        approved = False
        reasons.append(f"Projected total exposure ${projected_exposure:.2f} would exceed "
                        f"the portfolio cap of ${max_exposure_allowed:.2f} (25% of equity).")

    if account.get("daily_pl", 0) < -abs(equity * RISK_LIMITS["max_daily_loss_pct"]):
        approved = False
        reasons.append("Daily loss limit already breached; no new risk permitted today.")

    if not reasons:
        reasons.append("All deterministic risk gates passed.")

    return {
        "approved": approved,
        "reasons": reasons,
        "position_size": 1 if approved else 0,
        "max_loss_allowed": round(max_loss_allowed, 2),
        "checked_at": time.time(),
    }


# ---------------------------------------------------------------------------
# Stage 4: Execution
# ---------------------------------------------------------------------------

def execution(strategy: dict, risk: dict, adapter: MockAlpacaAdapter, run_id: str) -> dict:
    if not risk["approved"]:
        return {"submitted": False, "reason": "blocked_by_risk_manager"}

    net_price = sum(
        (leg["price"] if leg["action"] == "BUY" else -leg["price"]) for leg in strategy["legs"]
    )
    order = adapter.submit_option_order(
        symbol=strategy["symbol"],
        legs=strategy["legs"],
        qty=risk["position_size"],
        limit_price=round(net_price, 2),
        idempotency_key=f"run-{run_id}",
    )
    return {"submitted": True, "order": order}


# ---------------------------------------------------------------------------
# Stage 5: Learning -- real stats over whatever trade history actually exists.
# ---------------------------------------------------------------------------

def learning(trade_history: list[dict]) -> dict:
    closed = [t for t in trade_history if t.get("status") == "CLOSED"]
    if not closed:
        return {"sample_size": 0, "win_rate": None, "profit_factor": None,
                "recommendation": "Not enough closed trades yet to draw a conclusion."}

    wins = [t for t in closed if t["pnl"] > 0]
    losses = [t for t in closed if t["pnl"] <= 0]
    win_rate = round(100 * len(wins) / len(closed), 2)
    gross_win = sum(t["pnl"] for t in wins) or 0.0
    gross_loss = abs(sum(t["pnl"] for t in losses)) or 0.0001
    profit_factor = round(gross_win / gross_loss, 2)

    if win_rate >= 60 and profit_factor >= 1.5:
        rec = "Recent performance is strong; current strategy-selection rules are being kept as-is."
    elif win_rate < 40:
        rec = "Win rate is weak; consider raising the minimum confidence threshold."
    else:
        rec = "Performance is mixed; no rule changes recommended yet given the sample size."

    return {"sample_size": len(closed), "win_rate": win_rate,
            "profit_factor": profit_factor, "recommendation": rec}


# ---------------------------------------------------------------------------
# Orchestration
# ---------------------------------------------------------------------------

def run_pipeline(symbol: str, adapter: MockAlpacaAdapter, open_exposure: float,
                  killed: bool, trade_history: list[dict]) -> dict:
    run_id = str(uuid.uuid4())[:8]
    t0 = time.time()

    market_view = market_analyst(symbol)
    strategy = options_strategy(market_view)
    account = adapter.get_account()
    risk = risk_manager(strategy, account, open_exposure, killed)
    exec_result = execution(strategy, risk, adapter, run_id)
    learn = learning(trade_history)

    return {
        "run_id": run_id,
        "symbol": symbol,
        "started_at": t0,
        "duration_ms": round((time.time() - t0) * 1000, 2),
        "market_view": market_view,
        "strategy": strategy,
        "risk": risk,
        "execution": exec_result,
        "learning": learn,
    }
