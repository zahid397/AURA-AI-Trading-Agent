"""Regime -> strategy policy + candidate construction from a real option chain.

The regime-to-strategy mapping is the same transparent policy used in
legacy-prototype/backend/agents.py (STRATEGY_RULES), now selecting real
contracts from a supplied chain instead of inventing strikes. Confidence
comes from the Market Analyst's thesis, never recomputed here; this module
only picks contracts and does the deterministic payoff math.
"""
from __future__ import annotations

from decimal import Decimal
from uuid import uuid4

from app.alpaca.gateway import OptionContract
from app.strategies import payoff
from app.workflows.state import (
    Direction,
    MarketThesis,
    OptionLeg,
    OptionType,
    OrderSide,
    PositionIntent,
    ScanFilters,
    StrategyCandidate,
    StrategyType,
    TechnicalFeatures,
)

CONTRACT_MULTIPLIER = 100

_STRATEGY_RULES: dict[tuple[Direction, str], StrategyType] = {
    (Direction.BULLISH, "low"): StrategyType.BULL_CALL_SPREAD,
    (Direction.BULLISH, "moderate"): StrategyType.BULL_CALL_SPREAD,
    (Direction.BULLISH, "high"): StrategyType.CASH_SECURED_PUT,
    (Direction.BEARISH, "low"): StrategyType.BEAR_PUT_SPREAD,
    (Direction.BEARISH, "moderate"): StrategyType.BEAR_PUT_SPREAD,
    (Direction.BEARISH, "high"): StrategyType.BEAR_PUT_SPREAD,
    (Direction.NEUTRAL, "low"): StrategyType.COVERED_CALL,
    (Direction.NEUTRAL, "moderate"): StrategyType.IRON_CONDOR,
    (Direction.NEUTRAL, "high"): StrategyType.IRON_CONDOR,
}

_THESIS_TEMPLATES = {
    StrategyType.BULL_CALL_SPREAD: "Bullish thesis (confidence {conf:.0%}) in a {regime}-volatility regime. A debit call spread captures upside to the short strike while capping risk to the net debit paid.",
    StrategyType.BEAR_PUT_SPREAD: "Bearish thesis (confidence {conf:.0%}) in a {regime}-volatility regime. A debit put spread captures downside to the short strike with defined, capped risk.",
    StrategyType.IRON_CONDOR: "Range-bound/neutral thesis (confidence {conf:.0%}) with {regime} volatility. An iron condor collects premium if {symbol} stays between the short strikes through expiry.",
    StrategyType.COVERED_CALL: "Neutral-to-mildly-bullish thesis (confidence {conf:.0%}) with {regime} volatility. Selling a call above spot generates income against an assumed underlying position.",
    StrategyType.CASH_SECURED_PUT: "Bullish willingness-to-own thesis (confidence {conf:.0%}) but elevated ({regime}) volatility inflates put premium. Selling a cash-secured put earns income with a favorable entry price if assigned.",
}


def classify_vol_regime(realized_vol_annualized: float) -> str:
    if realized_vol_annualized > 0.30:
        return "high"
    if realized_vol_annualized < 0.15:
        return "low"
    return "moderate"


def select_strategy_type(direction: Direction, vol_regime: str) -> StrategyType:
    return _STRATEGY_RULES[(direction, vol_regime)]


def _closest(chain: list[OptionContract], option_type: str, target_strike: Decimal) -> OptionContract | None:
    candidates = [c for c in chain if c.option_type == option_type]
    if not candidates:
        return None
    return min(candidates, key=lambda c: abs(c.strike - target_strike))


def _to_leg(contract: OptionContract, side: OrderSide, position_intent: PositionIntent, ratio_qty: int = 1) -> OptionLeg:
    return OptionLeg(
        occ_symbol=contract.occ_symbol, underlying=contract.underlying,
        option_type=OptionType(contract.option_type), strike=contract.strike, expiration=contract.expiration,
        side=side, position_intent=position_intent, ratio_qty=ratio_qty,
        quote_bid=contract.quote_bid, quote_ask=contract.quote_ask, quote_mid=contract.quote_mid,
        delta=contract.delta, gamma=contract.gamma, theta=contract.theta, vega=contract.vega,
        open_interest=contract.open_interest, volume=contract.volume, quote_time=contract.quote_time,
    )


def _liquidity_score(legs: list[OptionLeg]) -> Decimal:
    if not legs:
        return Decimal(0)
    oi_scores = [min(Decimal(leg.open_interest or 0) / Decimal(500), Decimal(1)) for leg in legs]
    spread_scores = [
        Decimal(1) - min((leg.quote_ask - leg.quote_bid) / leg.quote_mid, Decimal(1)) if leg.quote_mid else Decimal(0)
        for leg in legs
    ]
    return ((sum(oi_scores) / len(oi_scores)) * Decimal("0.5") + (sum(spread_scores) / len(spread_scores)) * Decimal("0.5")).quantize(Decimal("0.001"))


def build_candidate(
    symbol: str,
    spot: Decimal,
    thesis: MarketThesis,
    features: TechnicalFeatures,
    chain: list[OptionContract],
    filters: ScanFilters,
    share_cost_basis: Decimal | None = None,
) -> StrategyCandidate | None:
    vol_regime = classify_vol_regime(float(features.realized_vol_annualized))
    strategy_type = select_strategy_type(thesis.direction, vol_regime)
    if filters.strategies and strategy_type not in filters.strategies:
        return None

    spot_f, vol_f = float(spot), float(features.realized_vol_annualized)
    dte = max(filters.min_dte, min(filters.max_dte, 30))
    legs: list[OptionLeg]

    def strike_at(pct_otm: float) -> Decimal:
        return Decimal(str(round(spot_f * (1 + pct_otm), 2)))

    if strategy_type == StrategyType.BULL_CALL_SPREAD:
        long_c = _closest(chain, "call", strike_at(0.01))
        short_c = _closest(chain, "call", strike_at(0.06))
        if not long_c or not short_c or long_c.strike >= short_c.strike:
            return None
        legs = [_to_leg(long_c, OrderSide.BUY, PositionIntent.BUY_TO_OPEN), _to_leg(short_c, OrderSide.SELL, PositionIntent.SELL_TO_OPEN)]
        result = payoff.bull_call_spread(long_c.strike, short_c.strike, long_c.quote_mid, short_c.quote_mid, CONTRACT_MULTIPLIER, spot_f, vol_f, dte)

    elif strategy_type == StrategyType.BEAR_PUT_SPREAD:
        long_p = _closest(chain, "put", strike_at(-0.01))
        short_p = _closest(chain, "put", strike_at(-0.06))
        if not long_p or not short_p or long_p.strike <= short_p.strike:
            return None
        legs = [_to_leg(long_p, OrderSide.BUY, PositionIntent.BUY_TO_OPEN), _to_leg(short_p, OrderSide.SELL, PositionIntent.SELL_TO_OPEN)]
        result = payoff.bear_put_spread(long_p.strike, short_p.strike, long_p.quote_mid, short_p.quote_mid, CONTRACT_MULTIPLIER, spot_f, vol_f, dte)

    elif strategy_type == StrategyType.IRON_CONDOR:
        long_p = _closest(chain, "put", strike_at(-0.08))
        short_p = _closest(chain, "put", strike_at(-0.04))
        short_c = _closest(chain, "call", strike_at(0.04))
        long_c = _closest(chain, "call", strike_at(0.08))
        if not all([long_p, short_p, short_c, long_c]) or not (long_p.strike < short_p.strike < short_c.strike < long_c.strike):
            return None
        legs = [
            _to_leg(long_p, OrderSide.BUY, PositionIntent.BUY_TO_OPEN), _to_leg(short_p, OrderSide.SELL, PositionIntent.SELL_TO_OPEN),
            _to_leg(short_c, OrderSide.SELL, PositionIntent.SELL_TO_OPEN), _to_leg(long_c, OrderSide.BUY, PositionIntent.BUY_TO_OPEN),
        ]
        result = payoff.iron_condor(
            long_p.strike, short_p.strike, short_c.strike, long_c.strike,
            long_p.quote_mid, short_p.quote_mid, short_c.quote_mid, long_c.quote_mid,
            CONTRACT_MULTIPLIER, spot_f, vol_f, dte,
        )

    elif strategy_type == StrategyType.COVERED_CALL:
        short_c = _closest(chain, "call", strike_at(0.05))
        if not short_c:
            return None
        legs = [_to_leg(short_c, OrderSide.SELL, PositionIntent.SELL_TO_OPEN)]
        basis = share_cost_basis if share_cost_basis is not None else spot
        result = payoff.covered_call(basis, short_c.strike, short_c.quote_mid, CONTRACT_MULTIPLIER, spot_f, vol_f, dte)

    else:  # CASH_SECURED_PUT
        short_p = _closest(chain, "put", strike_at(-0.05))
        if not short_p:
            return None
        legs = [_to_leg(short_p, OrderSide.SELL, PositionIntent.SELL_TO_OPEN)]
        result = payoff.cash_secured_put(short_p.strike, short_p.quote_mid, CONTRACT_MULTIPLIER, spot_f, vol_f, dte)

    liquidity = _liquidity_score(legs)
    thesis_text = _THESIS_TEMPLATES[strategy_type].format(conf=float(thesis.confidence), regime=vol_regime, symbol=symbol)

    weights = {"confidence": Decimal("0.4"), "liquidity": Decimal("0.3"), "return_on_risk": Decimal("0.3")}
    components = {
        "confidence": thesis.confidence,
        "liquidity": liquidity,
        "return_on_risk": min(result.return_on_risk, Decimal(3)) / Decimal(3),
    }
    score = sum(weights[k] * components[k] for k in weights).quantize(Decimal("0.001"))

    return StrategyCandidate(
        candidate_id=uuid4(), symbol=symbol, strategy_type=strategy_type, regime=thesis.direction,
        legs=legs, contract_multiplier=CONTRACT_MULTIPLIER,
        net_debit=result.net_debit, net_credit=result.net_credit,
        max_profit=result.max_profit, max_loss=result.max_loss, breakevens=result.breakevens,
        return_on_risk=result.return_on_risk, probability_of_profit=result.probability_of_profit,
        liquidity_score=liquidity, expected_return=(result.max_profit * result.probability_of_profit).quantize(Decimal("0.01")),
        dte=dte, quote_timestamp=legs[0].quote_time, score=score, score_components=components, score_weights=weights,
        thesis=thesis_text,
    )


def rank_candidates(candidates: list[StrategyCandidate]) -> list[StrategyCandidate]:
    ranked = sorted((c for c in candidates if c is not None), key=lambda c: c.score, reverse=True)
    for i, c in enumerate(ranked, start=1):
        c.rank = i
    return ranked
