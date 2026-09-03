from decimal import Decimal

from app.indicators.options_pricing import (
    black_scholes,
    probability_price_above,
    probability_price_below,
)


def test_put_call_parity():
    """C - P = S - K*e^(-rT). If this holds, both pricers are internally
    consistent (same as legacy-prototype's verification of the original
    float implementation this was ported from)."""
    spot, strike, days, vol, rate = 100.0, 105.0, 30, 0.25, 0.045
    call = black_scholes(spot, strike, days, vol, rate, "call")
    put = black_scholes(spot, strike, days, vol, rate, "put")
    t = days / 365.0
    lhs = float(call.price) - float(put.price)
    rhs = spot - strike * pow(2.718281828459045, -rate * t)
    assert abs(lhs - rhs) < 0.05


def test_call_price_increases_with_spot():
    low = black_scholes(90.0, 100.0, 30, 0.25, option_type="call")
    high = black_scholes(110.0, 100.0, 30, 0.25, option_type="call")
    assert high.price > low.price


def test_deep_itm_call_delta_near_one():
    result = black_scholes(200.0, 100.0, 30, 0.20, option_type="call")
    assert result.delta > Decimal("0.9")


def test_deep_otm_put_delta_near_zero():
    result = black_scholes(200.0, 100.0, 30, 0.20, option_type="put")
    assert abs(result.delta) < Decimal("0.1")


def test_invalid_option_type_raises():
    import pytest
    with pytest.raises(ValueError):
        black_scholes(100.0, 100.0, 30, 0.2, option_type="straddle")


def test_price_is_quantized_to_cents():
    result = black_scholes(100.0, 100.0, 30, 0.25, option_type="call")
    assert result.price == result.price.quantize(Decimal("0.01"))


def test_probability_above_and_below_sum_to_one():
    p_above = probability_price_above(100.0, 105.0, 30, 0.25)
    p_below = probability_price_below(100.0, 105.0, 30, 0.25)
    assert abs((p_above + p_below) - 1.0) < 1e-9
