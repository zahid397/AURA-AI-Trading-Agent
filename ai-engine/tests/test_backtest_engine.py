from decimal import Decimal

from app.backtesting.engine import run_backtest


def test_backtest_is_deterministic_for_the_same_inputs():
    r1 = run_backtest(["SPY"], lookback_days=200)
    r2 = run_backtest(["SPY"], lookback_days=200)
    assert r1.trade_count == r2.trade_count
    assert r1.total_return_pct == r2.total_return_pct
    assert [t.pnl for t in r1.trades] == [t.pnl for t in r2.trades]


def test_backtest_labels_itself_as_simulated_and_never_as_historical():
    result = run_backtest(["NVDA"], lookback_days=200)
    assert result.data_source == "simulated_fixture"
    assert "not real historical prices" in result.assumptions["data_source"].lower() or "synthetic" in result.assumptions["data_source"].lower()


def test_backtest_produces_a_coherent_equity_curve_and_metrics():
    result = run_backtest(["TSLA", "SPY"], lookback_days=250, starting_capital=Decimal("100000.00"))
    assert result.equity_curve[0] == Decimal("100000.00")
    assert result.max_drawdown_pct >= Decimal(0)
    assert 0 <= result.win_rate <= 1
    for trade in result.trades:
        if trade.pnl is not None:
            assert trade.exit_reason in ("profit_target", "stop_loss", "time_exit")


def test_no_lookahead_more_history_does_not_change_earlier_trade_entries():
    """Widening the backtest window must never change what happened on
    earlier days. A trade that *closed* within the short window must close
    on the same day, with the same P&L, in the longer run too. (A trade
    still open when the short window ends is simply absent from its ledger
    -- that's an honest, documented limitation, not a look-ahead bug, so we
    only compare trades that both runs actually got to close.)"""
    short = run_backtest(["SPY"], lookback_days=150)
    longer = run_backtest(["SPY"], lookback_days=200)
    longer_by_entry_day = {t.entry_day: t for t in longer.trades}

    assert len(short.trades) > 0
    for trade in short.trades:
        match = longer_by_entry_day.get(trade.entry_day)
        assert match is not None, f"entry on day {trade.entry_day} in the short run is missing entirely from the longer run"
        assert match.exit_day == trade.exit_day
        assert match.pnl == trade.pnl
        assert match.exit_reason == trade.exit_reason
