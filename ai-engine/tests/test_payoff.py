from decimal import Decimal

import pytest

from app.strategies import payoff


def test_bull_call_spread_basic_math():
    result = payoff.bull_call_spread(
        long_strike=Decimal(100), short_strike=Decimal(110),
        long_premium=Decimal("4.00"), short_premium=Decimal("1.50"),
        multiplier=100, spot=101.0, vol=0.25, dte=30,
    )
    assert result.net_debit == Decimal("2.50")
    assert result.max_loss == Decimal("250.00")
    assert result.max_profit == Decimal("750.00")  # (10 - 2.50) * 100
    assert result.breakevens == [Decimal("102.50")]
    assert Decimal(0) <= result.probability_of_profit <= Decimal(1)


def test_bull_call_spread_rejects_bad_strikes():
    with pytest.raises(ValueError):
        payoff.bull_call_spread(
            long_strike=Decimal(110), short_strike=Decimal(100),
            long_premium=Decimal(1), short_premium=Decimal(2),
            multiplier=100, spot=100.0, vol=0.2, dte=30,
        )


def test_bear_put_spread_basic_math():
    result = payoff.bear_put_spread(
        long_strike=Decimal(100), short_strike=Decimal(90),
        long_premium=Decimal("4.00"), short_premium=Decimal("1.50"),
        multiplier=100, spot=99.0, vol=0.25, dte=30,
    )
    assert result.net_debit == Decimal("2.50")
    assert result.max_loss == Decimal("250.00")
    assert result.max_profit == Decimal("750.00")
    assert result.breakevens == [Decimal("97.50")]


def test_iron_condor_basic_math():
    result = payoff.iron_condor(
        long_put_strike=Decimal(90), short_put_strike=Decimal(95),
        short_call_strike=Decimal(105), long_call_strike=Decimal(110),
        long_put_premium=Decimal("0.50"), short_put_premium=Decimal("1.20"),
        short_call_premium=Decimal("1.10"), long_call_premium=Decimal("0.40"),
        multiplier=100, spot=100.0, vol=0.22, dte=30,
    )
    net_credit = (Decimal("1.20") + Decimal("1.10")) - (Decimal("0.50") + Decimal("0.40"))
    assert result.net_credit == net_credit
    assert result.max_profit == net_credit * 100
    assert result.max_loss == Decimal("500.00") - net_credit * 100
    assert result.breakevens[0] < Decimal(100) < result.breakevens[1]


def test_iron_condor_rejects_unordered_strikes():
    with pytest.raises(ValueError):
        payoff.iron_condor(
            long_put_strike=Decimal(95), short_put_strike=Decimal(90),
            short_call_strike=Decimal(105), long_call_strike=Decimal(110),
            long_put_premium=Decimal("0.5"), short_put_premium=Decimal("1.2"),
            short_call_premium=Decimal("1.1"), long_call_premium=Decimal("0.4"),
            multiplier=100, spot=100.0, vol=0.22, dte=30,
        )


def test_covered_call_max_profit_and_breakeven():
    result = payoff.covered_call(
        share_cost_basis=Decimal("98.00"), call_strike=Decimal(105), call_premium=Decimal("1.50"),
        multiplier=100, spot=100.0, vol=0.18, dte=30,
    )
    assert result.max_profit == Decimal("850.00")  # (105-98)*100 + 1.50*100
    assert result.breakevens == [Decimal("96.50")]


def test_cash_secured_put_max_loss():
    result = payoff.cash_secured_put(
        put_strike=Decimal(95), put_premium=Decimal("1.80"),
        multiplier=100, spot=100.0, vol=0.20, dte=30,
    )
    assert result.max_profit == Decimal("180.00")
    assert result.max_loss == Decimal("9320.00")  # 95*100 - 1.80*100
    assert result.breakevens == [Decimal("93.20")]
