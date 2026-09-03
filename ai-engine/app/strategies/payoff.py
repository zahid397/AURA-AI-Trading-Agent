"""Deterministic payoff math for the five supported options strategies.

Every function here takes already-known premiums/strikes (Decimal, real
money) and returns net debit/credit, max profit, max loss, and breakeven(s).
No LLM call, no randomness -- these are the numbers the Risk Guardian and the
Strategy Analysis page treat as ground truth. Probability-of-profit uses the
Black-Scholes closed-form proxy in `app/indicators/options_pricing.py`
(model-based, explicitly labeled as such downstream).
"""
from __future__ import annotations

from dataclasses import dataclass
from decimal import ROUND_HALF_EVEN, Decimal

from app.indicators.options_pricing import (
    probability_price_above,
    probability_price_below,
)

CENT = Decimal("0.01")


def _money(x: Decimal) -> Decimal:
    return x.quantize(CENT, rounding=ROUND_HALF_EVEN)


@dataclass(frozen=True)
class PayoffResult:
    net_debit: Decimal | None
    net_credit: Decimal | None
    max_profit: Decimal
    max_loss: Decimal
    breakevens: list[Decimal]
    return_on_risk: Decimal
    probability_of_profit: Decimal


def _return_on_risk(max_profit: Decimal, max_loss: Decimal) -> Decimal:
    if max_loss <= 0:
        return Decimal(0)
    return _money(max_profit / max_loss).quantize(Decimal("0.001"))


def bull_call_spread(
    long_strike: Decimal, short_strike: Decimal,
    long_premium: Decimal, short_premium: Decimal,
    multiplier: int, spot: float, vol: float, dte: int,
) -> PayoffResult:
    if short_strike <= long_strike:
        raise ValueError("bull call spread requires short_strike > long_strike")
    net_debit = _money(long_premium - short_premium)
    width = short_strike - long_strike
    max_profit = _money(width * multiplier - net_debit * multiplier)
    max_loss = _money(net_debit * multiplier)
    breakeven = long_strike + net_debit
    pop = probability_price_above(spot, float(breakeven), dte, vol)
    return PayoffResult(net_debit, None, max_profit, max_loss, [breakeven], _return_on_risk(max_profit, max_loss), Decimal(str(round(pop, 4))))


def bear_put_spread(
    long_strike: Decimal, short_strike: Decimal,
    long_premium: Decimal, short_premium: Decimal,
    multiplier: int, spot: float, vol: float, dte: int,
) -> PayoffResult:
    if long_strike <= short_strike:
        raise ValueError("bear put spread requires long_strike > short_strike")
    net_debit = _money(long_premium - short_premium)
    width = long_strike - short_strike
    max_profit = _money(width * multiplier - net_debit * multiplier)
    max_loss = _money(net_debit * multiplier)
    breakeven = long_strike - net_debit
    pop = probability_price_below(spot, float(breakeven), dte, vol)
    return PayoffResult(net_debit, None, max_profit, max_loss, [breakeven], _return_on_risk(max_profit, max_loss), Decimal(str(round(pop, 4))))


def iron_condor(
    long_put_strike: Decimal, short_put_strike: Decimal,
    short_call_strike: Decimal, long_call_strike: Decimal,
    long_put_premium: Decimal, short_put_premium: Decimal,
    short_call_premium: Decimal, long_call_premium: Decimal,
    multiplier: int, spot: float, vol: float, dte: int,
) -> PayoffResult:
    if not (long_put_strike < short_put_strike < short_call_strike < long_call_strike):
        raise ValueError("iron condor strikes must satisfy long_put < short_put < short_call < long_call")
    net_credit = _money((short_put_premium + short_call_premium) - (long_put_premium + long_call_premium))
    put_width = short_put_strike - long_put_strike
    call_width = long_call_strike - short_call_strike
    widest = max(put_width, call_width)
    max_profit = _money(net_credit * multiplier)
    max_loss = _money(widest * multiplier - net_credit * multiplier)
    lower_breakeven = short_put_strike - net_credit
    upper_breakeven = short_call_strike + net_credit
    pop_lower = probability_price_above(spot, float(lower_breakeven), dte, vol)
    pop_upper = probability_price_below(spot, float(upper_breakeven), dte, vol)
    pop = max(0.0, pop_lower + pop_upper - 1.0)  # P(between the breakevens)
    return PayoffResult(
        None, net_credit, max_profit, max_loss,
        [lower_breakeven, upper_breakeven],
        _return_on_risk(max_profit, max_loss),
        Decimal(str(round(pop, 4))),
    )


def covered_call(
    share_cost_basis: Decimal, call_strike: Decimal, call_premium: Decimal,
    multiplier: int, spot: float, vol: float, dte: int,
) -> PayoffResult:
    max_profit = _money((call_strike - share_cost_basis) * multiplier + call_premium * multiplier)
    max_loss = _money(share_cost_basis * multiplier - call_premium * multiplier)  # theoretical, shares to $0
    breakeven = share_cost_basis - call_premium
    pop = probability_price_above(spot, float(breakeven), dte, vol)
    return PayoffResult(None, _money(call_premium), max_profit, max_loss, [breakeven], _return_on_risk(max_profit, max_loss), Decimal(str(round(pop, 4))))


def cash_secured_put(
    put_strike: Decimal, put_premium: Decimal,
    multiplier: int, spot: float, vol: float, dte: int,
) -> PayoffResult:
    max_profit = _money(put_premium * multiplier)
    max_loss = _money(put_strike * multiplier - put_premium * multiplier)  # theoretical, assigned then stock to $0
    breakeven = put_strike - put_premium
    pop = probability_price_above(spot, float(breakeven), dte, vol)
    return PayoffResult(None, _money(put_premium), max_profit, max_loss, [breakeven], _return_on_risk(max_profit, max_loss), Decimal(str(round(pop, 4))))


def payoff_curve(
    strategy_type: str,
    legs_payoff_at: callable,
    price_range: list[Decimal],
) -> list[tuple[Decimal, Decimal]]:
    """Generic P&L-at-expiration curve: `legs_payoff_at(price) -> Decimal` is
    supplied by the caller (it already knows each leg's strike/side/premium);
    this just samples it across `price_range` for charting."""
    return [(p, legs_payoff_at(p)) for p in price_range]
