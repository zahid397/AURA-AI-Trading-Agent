from app.indicators import technical as t


def test_sma_needs_full_window():
    assert t.sma([1, 2], 5) is None
    assert t.sma([1, 2, 3, 4, 5], 5) == 3


def test_rsi_all_gains_is_100():
    series = [float(i) for i in range(1, 20)]  # strictly increasing
    assert t.rsi(series, 14) == 100.0


def test_rsi_all_losses_is_zero():
    series = [float(i) for i in range(20, 1, -1)]  # strictly decreasing
    assert t.rsi(series, 14) == 0.0


def test_ema_trend_bullish_on_rising_series():
    series = [100 + i * 0.5 for i in range(40)]
    assert t.ema_trend(series) == "bullish"


def test_ema_trend_bearish_on_falling_series():
    series = [140 - i * 0.5 for i in range(40)]
    assert t.ema_trend(series) == "bearish"


def test_atr_requires_window_plus_one_bars():
    highs = [10.0] * 10
    lows = [9.0] * 10
    closes = [9.5] * 10
    assert t.atr(highs, lows, closes, window=14) is None


def test_realized_volatility_is_nonnegative():
    series = [100.0, 101.0, 99.5, 102.0, 98.0] * 5
    vol = t.realized_volatility(series, window=20)
    assert vol is not None and vol >= 0


def test_relative_volume_above_average():
    volumes = [100] * 20 + [500]
    assert t.relative_volume(volumes, window=20) == 5.0


def test_iv_percentile_none_without_history():
    assert t.iv_percentile(0.3, []) is None


def test_iv_percentile_computed_with_history():
    result = t.iv_percentile(0.3, [0.1, 0.2, 0.25, 0.35, 0.4])
    assert result == 60.0
