"""Black-Scholes-Merton option pricing and Greeks.

Ported from legacy-prototype/backend/market_math.py, whose formulas were
verified against put-call parity (see legacy-prototype/backend/tests). The
internal math is float (transcendental functions -- erf, log, sqrt, exp --
have no exact Decimal equivalent in the stdlib), but every dollar-denominated
result is quantized to Decimal(\"0.01\") before it leaves this module. Nothing
downstream should ever see a raw float price.
"""
from __future__ import annotations

import math
from dataclasses import dataclass
from decimal import ROUND_HALF_EVEN, Decimal

CENT = Decimal("0.01")


def _to_money(x: float) -> Decimal:
    return Decimal(str(round(max(x, 0.0), 4))).quantize(CENT, rounding=ROUND_HALF_EVEN)


def _norm_cdf(x: float) -> float:
    return 0.5 * (1 + math.erf(x / math.sqrt(2)))


def _norm_pdf(x: float) -> float:
    return math.exp(-x * x / 2) / math.sqrt(2 * math.pi)


@dataclass(frozen=True)
class OptionPricingResult:
    price: Decimal
    delta: Decimal
    gamma: Decimal
    theta: Decimal
    vega: Decimal


def black_scholes(
    spot: float,
    strike: float,
    days_to_expiry: int,
    vol: float,
    rate: float = 0.045,
    option_type: str = "call",
) -> OptionPricingResult:
    """Standard Black-Scholes-Merton price and Greeks, no dividend yield term.

    `spot`/`strike`/`vol` are inputs to a pricing *model*, taken in as float;
    the returned `price` is quantized to cents as a Decimal since it
    represents money.
    """
    if option_type not in ("call", "put"):
        raise ValueError(f"option_type must be 'call' or 'put', got {option_type!r}")

    t = max(days_to_expiry, 1) / 365.0
    safe_vol = vol if vol > 0 else 0.0001
    d1 = (math.log(spot / strike) + (rate + 0.5 * safe_vol**2) * t) / (safe_vol * math.sqrt(t))
    d2 = d1 - safe_vol * math.sqrt(t)

    if option_type == "call":
        price = spot * _norm_cdf(d1) - strike * math.exp(-rate * t) * _norm_cdf(d2)
        delta = _norm_cdf(d1)
    else:
        price = strike * math.exp(-rate * t) * _norm_cdf(-d2) - spot * _norm_cdf(-d1)
        delta = _norm_cdf(d1) - 1

    gamma = _norm_pdf(d1) / (spot * safe_vol * math.sqrt(t))
    vega = spot * _norm_pdf(d1) * math.sqrt(t) / 100  # dollars per 1 vol point
    theta_annual = (
        -(spot * _norm_pdf(d1) * safe_vol) / (2 * math.sqrt(t))
        - rate * strike * math.exp(-rate * t) * (_norm_cdf(d2) if option_type == "call" else _norm_cdf(-d2))
    )
    theta_daily = theta_annual / 365

    return OptionPricingResult(
        price=_to_money(price),
        delta=Decimal(str(round(delta, 4))),
        gamma=Decimal(str(round(gamma, 5))),
        theta=Decimal(str(round(theta_daily, 4))),
        vega=Decimal(str(round(vega, 4))),
    )


def implied_move(spot: float, vol: float, days: int) -> Decimal:
    """One-standard-deviation expected price move over `days`, in dollars."""
    t = days / 365.0
    return _to_money(spot * vol * math.sqrt(t))


def probability_price_above(spot: float, target: float, days: int, vol: float, rate: float = 0.045) -> float:
    """Risk-neutral probability the underlying finishes above `target` at
    expiry, using the Black-Scholes N(d2) term (the standard closed-form
    proxy for probability-of-finishing-ITM). This is a model-based estimate,
    not a guarantee -- surfaced to the UI as "probability of profit", never
    as a calibrated ML forecast."""
    t = max(days, 1) / 365.0
    safe_vol = vol if vol > 0 else 0.0001
    d2 = (math.log(spot / target) + (rate - 0.5 * safe_vol**2) * t) / (safe_vol * math.sqrt(t))
    return _norm_cdf(d2)


def probability_price_below(spot: float, target: float, days: int, vol: float, rate: float = 0.045) -> float:
    return 1.0 - probability_price_above(spot, target, days, vol, rate)
