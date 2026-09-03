from decimal import Decimal

from app.monitoring.exit_rules import check_exit, describe_exit_conditions


def test_profit_target_triggers_at_the_configured_percentage():
    result = check_exit(unrealized_pnl=Decimal(55), max_profit=Decimal(100), max_loss=Decimal(400), remaining_dte=20)
    assert result.should_exit is True
    assert result.reason == "profit_target"


def test_stop_loss_triggers_at_the_configured_percentage():
    result = check_exit(unrealized_pnl=Decimal(-400), max_profit=Decimal(100), max_loss=Decimal(400), remaining_dte=20)
    assert result.should_exit is True
    assert result.reason == "stop_loss"


def test_time_exit_triggers_regardless_of_pnl():
    result = check_exit(unrealized_pnl=Decimal(0), max_profit=Decimal(100), max_loss=Decimal(400), remaining_dte=1)
    assert result.should_exit is True
    assert result.reason == "time_exit"


def test_no_exit_when_nothing_is_triggered():
    result = check_exit(unrealized_pnl=Decimal(10), max_profit=Decimal(100), max_loss=Decimal(400), remaining_dte=20)
    assert result.should_exit is False
    assert result.reason is None


def test_describe_exit_conditions_is_human_readable():
    lines = describe_exit_conditions(Decimal(200))
    assert len(lines) == 3
    assert any("profit target" in line.lower() for line in lines)
    assert any("stop loss" in line.lower() for line in lines)
    assert any("time exit" in line.lower() for line in lines)
