"""Deterministic technical indicators computed from OHLCV bars.

Ported and extended from legacy-prototype/backend/market_math.py (that
module's SMA/RSI/realized-volatility implementations were unit-tested against
known values and put-call parity; the formulas here are unchanged, just typed
and extended with EMA/MACD/ATR to match the full feature set the Market
Analyst Agent needs).

These are float computations by design -- they are statistical/technical
estimates, not ledger money. Anything that becomes a dollar amount (option
prices, P&L) is converted to Decimal at the point it is computed in
`options_pricing.py`, never here.
"""
from __future__ import annotations

import math

Number = float


def sma(series: list[Number], window: int) -> float | None:
    if len(series) < window:
        return None
    return sum(series[-window:]) / window


def ema_series(series: list[Number], window: int) -> list[float]:
    """Full EMA series (not just the last value) so trend direction can be
    read from the last two points."""
    if not series:
        return []
    k = 2 / (window + 1)
    out = [series[0]]
    for price in series[1:]:
        out.append(price * k + out[-1] * (1 - k))
    return out


def ema(series: list[Number], window: int) -> float | None:
    if len(series) < window:
        return None
    return ema_series(series, window)[-1]


def ema_trend(series: list[Number], fast: int = 12, slow: int = 26) -> str:
    """'bullish' | 'bearish' | 'neutral' from a fast/slow EMA crossover."""
    if len(series) < slow + 1:
        return "neutral"
    fast_series = ema_series(series, fast)
    slow_series = ema_series(series, slow)
    fast_now, fast_prev = fast_series[-1], fast_series[-2]
    slow_now, slow_prev = slow_series[-1], slow_series[-2]
    now_diff = fast_now - slow_now
    prev_diff = fast_prev - slow_prev
    if now_diff > 0 and now_diff >= prev_diff:
        return "bullish"
    if now_diff < 0 and now_diff <= prev_diff:
        return "bearish"
    return "neutral"


def rsi(series: list[Number], window: int = 14) -> float | None:
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
    return 100 - (100 / (1 + rs))


def macd(series: list[Number], fast: int = 12, slow: int = 26, signal: int = 9) -> tuple[float, float] | None:
    """Returns (macd_line, signal_line) using the standard 12/26/9 config."""
    if len(series) < slow + signal:
        return None
    fast_series = ema_series(series, fast)
    slow_series = ema_series(series, slow)
    n = min(len(fast_series), len(slow_series))
    macd_line_series = [fast_series[-n + i] - slow_series[-n + i] for i in range(n)]
    signal_line = ema_series(macd_line_series, signal)[-1]
    return macd_line_series[-1], signal_line


def atr(highs: list[Number], lows: list[Number], closes: list[Number], window: int = 14) -> float | None:
    """Average True Range (Wilder). Needs high/low/close, not just close."""
    n = len(closes)
    if n < window + 1:
        return None
    true_ranges = []
    for i in range(-window, 0):
        h, lo, prev_close = highs[i], lows[i], closes[i - 1]
        true_ranges.append(max(h - lo, abs(h - prev_close), abs(lo - prev_close)))
    return sum(true_ranges) / window


def realized_volatility(series: list[Number], window: int = 20, annualize: bool = True) -> float | None:
    """Annualized realized volatility from daily log returns."""
    if len(series) < window + 1:
        return None
    rets = [math.log(series[i] / series[i - 1]) for i in range(-window, 0)]
    mean = sum(rets) / len(rets)
    var = sum((r - mean) ** 2 for r in rets) / (len(rets) - 1)
    daily_vol = math.sqrt(var)
    return daily_vol * math.sqrt(252) if annualize else daily_vol


def relative_volume(volumes: list[int], window: int = 20) -> float | None:
    """Latest volume vs its trailing average -- >1 means above-average activity."""
    if len(volumes) < window + 1:
        return None
    avg = sum(volumes[-window - 1:-1]) / window
    if avg == 0:
        return None
    return volumes[-1] / avg


def iv_percentile(current_iv: float, iv_history: list[float]) -> float | None:
    """Percentile rank of current IV within its own trailing history.
    Requires real historical IV samples; returns None (not a guess) if the
    data feed doesn't supply them -- callers must surface this as a
    data-quality warning rather than fabricate a value."""
    if not iv_history:
        return None
    below = sum(1 for v in iv_history if v <= current_iv)
    return (below / len(iv_history)) * 100
