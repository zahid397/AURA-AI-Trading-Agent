"""
market_math.py

Two honestly-separate things live in this file:

1. `synthetic_price_series(...)` -- a deterministic *simulated* random-walk price
   series, seeded per symbol. This is NOT real market data. There is no live feed
   in this build (no network path to one). It exists so the rest of the pipeline
   has believable, internally-consistent numbers to compute over.

2. Everything below that IS real, standard quantitative finance math (SMA, RSI,
   realized volatility, Black-Scholes option pricing and Greeks) implemented from
   first principles using only the Python standard library (`math.erf` for the
   normal CDF -- no scipy needed, and scipy is not installed / not installable
   here anyway). Given a price series and a volatility estimate, these numbers
   are genuinely computed, not hardcoded.
"""

from __future__ import annotations
import math
import random
from dataclasses import dataclass


def synthetic_price_series(symbol: str, days: int = 90, start_price: float | None = None) -> list[float]:
    """Deterministic per-symbol random walk. Same symbol -> same series, every run."""
    seed = sum(ord(c) for c in symbol) * 1000 + days
    rng = random.Random(seed)
    price = start_price if start_price is not None else 100 + (seed % 400)
    prices = [price]
    # mild upward drift + daily noise, clipped so it can't wander to <= 0
    for _ in range(days - 1):
        daily_return = rng.gauss(0.0003, 0.015)
        price = max(1.0, price * (1 + daily_return))
        prices.append(round(price, 2))
    return prices


def sma(series: list[float], window: int) -> float | None:
    if len(series) < window:
        return None
    return round(sum(series[-window:]) / window, 4)


def rsi(series: list[float], window: int = 14) -> float | None:
    """Standard Wilder RSI over the trailing `window` periods."""
    if len(series) < window + 1:
        return None
    gains, losses = [], []
    for i in range(-window, 0):
        change = series[i] - series[i - 1]
        gains.append(max(change, 0))
        losses.append(max(-change, 0))
    avg_gain = sum(gains) / window
    avg_loss = sum(losses) / window
    if avg_loss == 0:
        return 100.0
    rs = avg_gain / avg_loss
    return round(100 - (100 / (1 + rs)), 2)


def realized_volatility(series: list[float], window: int = 20, annualize: bool = True) -> float | None:
    """Annualized realized volatility from daily log returns (standard estimator)."""
    if len(series) < window + 1:
        return None
    rets = []
    for i in range(-window, 0):
        rets.append(math.log(series[i] / series[i - 1]))
    mean = sum(rets) / len(rets)
    var = sum((r - mean) ** 2 for r in rets) / (len(rets) - 1)
    daily_vol = math.sqrt(var)
    return round(daily_vol * math.sqrt(252), 4) if annualize else round(daily_vol, 4)


def _norm_cdf(x: float) -> float:
    return 0.5 * (1 + math.erf(x / math.sqrt(2)))


def _norm_pdf(x: float) -> float:
    return math.exp(-x * x / 2) / math.sqrt(2 * math.pi)


@dataclass
class BSResult:
    price: float
    delta: float
    gamma: float
    theta: float
    vega: float


def black_scholes(spot: float, strike: float, days_to_expiry: int, vol: float,
                   rate: float = 0.045, option_type: str = "call") -> BSResult:
    """
    Standard Black-Scholes-Merton price and Greeks (no dividend yield term).
    Textbook formulas -- real computation, not a placeholder.
    """
    t = max(days_to_expiry, 1) / 365.0
    if vol <= 0:
        vol = 0.0001
    d1 = (math.log(spot / strike) + (rate + 0.5 * vol ** 2) * t) / (vol * math.sqrt(t))
    d2 = d1 - vol * math.sqrt(t)

    if option_type == "call":
        price = spot * _norm_cdf(d1) - strike * math.exp(-rate * t) * _norm_cdf(d2)
        delta = _norm_cdf(d1)
    else:
        price = strike * math.exp(-rate * t) * _norm_cdf(-d2) - spot * _norm_cdf(-d1)
        delta = _norm_cdf(d1) - 1

    gamma = _norm_pdf(d1) / (spot * vol * math.sqrt(t))
    vega = spot * _norm_pdf(d1) * math.sqrt(t) / 100  # per 1 vol point
    theta_annual = (
        -(spot * _norm_pdf(d1) * vol) / (2 * math.sqrt(t))
        - rate * strike * math.exp(-rate * t) * (_norm_cdf(d2) if option_type == "call" else _norm_cdf(-d2))
    )
    theta_daily = theta_annual / 365

    return BSResult(
        price=round(max(price, 0.0), 2),
        delta=round(delta, 3),
        gamma=round(gamma, 5),
        theta=round(theta_daily, 3),
        vega=round(vega, 3),
    )
