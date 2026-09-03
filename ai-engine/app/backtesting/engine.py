"""A credible, honestly-labeled backtest engine.

There is no real historical options-chain data source wired into this build
(no paid entitlement, no vendor feed) -- per the master spec's own fallback
("support a fixture-backed demonstration and clearly state the limitation"),
this engine runs against the same deterministic synthetic price generator the
demo adapter uses (`app.alpaca.demo_adapter.synthetic_price_series`), and
every result is labeled `data_source: "simulated_fixture"`. It is NOT
presented as historical performance.

No look-ahead: features on day `i` are computed only from bars `[0, i]`.
Entries use the same regime -> strategy policy as the live agents
(`app.strategies.selector`); exits are profit-target / stop-loss / time-based,
checked day by day using Black-Scholes repricing at each day's synthetic spot
and shrinking DTE (not just the payoff-at-expiration curve), so an exit
before expiration reflects a real repricing, not just intrinsic value.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta
from decimal import Decimal

from app.agents import market_analyst
from app.alpaca.demo_adapter import synthetic_price_series
from app.indicators.options_pricing import black_scholes
from app.monitoring.exit_rules import (
    MIN_DTE_FOR_TIME_EXIT,
    PROFIT_TARGET_PCT_OF_MAX,
    STOP_LOSS_PCT_OF_MAX,
    check_exit,
)
from app.strategies.selector import classify_vol_regime, select_strategy_type
from app.workflows.state import Bar, Direction, MarketDataSnapshot, StrategyType

CONTRACT_MULTIPLIER = 100


@dataclass
class BacktestTrade:
    symbol: str
    strategy_type: str
    entry_day: int
    entry_date: str
    exit_day: int | None = None
    exit_date: str | None = None
    entry_price: Decimal = Decimal(0)
    exit_price: Decimal | None = None
    pnl: Decimal | None = None
    exit_reason: str | None = None


@dataclass
class BacktestResult:
    data_source: str
    symbols: list[str]
    trades: list[BacktestTrade] = field(default_factory=list)
    equity_curve: list[Decimal] = field(default_factory=list)
    starting_capital: Decimal = Decimal("100000.00")
    total_return_pct: Decimal = Decimal(0)
    win_rate: Decimal = Decimal(0)
    profit_factor: Decimal | None = None
    max_drawdown_pct: Decimal = Decimal(0)
    trade_count: int = 0
    assumptions: dict[str, str] = field(default_factory=dict)


def _max_drawdown(equity_curve: list[Decimal]) -> Decimal:
    if not equity_curve:
        return Decimal(0)
    peak = equity_curve[0]
    worst = Decimal(0)
    for value in equity_curve:
        peak = max(peak, value)
        if peak > 0:
            worst = max(worst, (peak - value) / peak)
    return worst.quantize(Decimal("0.0001"))


def run_backtest(
    symbols: list[str],
    lookback_days: int = 180,
    dte: int = 30,
    min_confidence: Decimal = Decimal("0.55"),
    slippage_pct: Decimal = Decimal("0.02"),
    starting_capital: Decimal = Decimal("100000.00"),
    seed_anchor: date | None = None,
) -> BacktestResult:
    """Deterministic given the same inputs -- same `symbols`/`lookback_days`
    always produce the same trade ledger, since the underlying price series
    is seeded from the symbol name (see `synthetic_price_series`)."""
    anchor = seed_anchor or date(2026, 1, 1)
    equity = starting_capital
    equity_curve = [equity]
    trades: list[BacktestTrade] = []

    warmup = 40  # bars needed before indicators are meaningful (EMA-26 + MACD signal)
    for symbol in symbols:
        prices = synthetic_price_series(symbol, days=lookback_days)
        open_trade: dict | None = None

        for day in range(warmup, len(prices)):
            window = prices[: day + 1]
            bars = [
                Bar(
                    t=anchor + timedelta(days=i), open=Decimal(str(p)),
                    high=Decimal(str(round(p * 1.01, 2))), low=Decimal(str(round(p * 0.99, 2))),
                    close=Decimal(str(p)), volume=1_000_000,
                )
                for i, p in enumerate(window)
            ]
            snapshot = MarketDataSnapshot(symbol=symbol, last_price=Decimal(str(window[-1])), bars=bars, quote_time=bars[-1].t, is_stale=False, data_source="simulated_fixture")
            features = market_analyst.compute_features(symbol, snapshot)
            spot = window[-1]
            vol = float(features.realized_vol_annualized) or 0.20

            if open_trade is not None:
                days_held = day - open_trade["entry_day"]
                remaining_dte = max(open_trade["dte"] - days_held, 0)
                current_price = float(black_scholes(spot, open_trade["strike"], max(remaining_dte, 1), vol, option_type=open_trade["option_type"]).price)
                entry_price = float(open_trade["entry_price"])
                pnl_per_share = (entry_price - current_price) if open_trade["is_long"] else (current_price - entry_price)
                pnl = Decimal(str(round(pnl_per_share * CONTRACT_MULTIPLIER, 2)))
                # Single-leg long-premium proxy: both the profit-target and stop-loss percentages
                # are taken against the debit paid (there's no separate spread-width max profit here).
                debit_at_risk = (open_trade["entry_price"] * CONTRACT_MULTIPLIER).quantize(Decimal("0.01"))
                exit_check = check_exit(pnl, max_profit=debit_at_risk, max_loss=debit_at_risk, remaining_dte=remaining_dte)

                if exit_check.should_exit:
                    slip = Decimal(str(current_price)) * slippage_pct
                    realized_pnl = pnl - (slip * CONTRACT_MULTIPLIER)
                    equity += realized_pnl
                    trade = open_trade["trade"]
                    trade.exit_day, trade.exit_date = day, str(anchor + timedelta(days=day))
                    trade.exit_price, trade.pnl, trade.exit_reason = Decimal(str(round(current_price, 2))), realized_pnl.quantize(Decimal("0.01")), exit_check.reason
                    trades.append(trade)
                    open_trade = None
                    equity_curve.append(equity)
                continue

            if len(window) < warmup + 1:
                continue
            direction = features.ema_trend
            regime = classify_vol_regime(vol)
            confidence_f, _ = market_analyst.heuristic_confidence(features)
            if confidence_f < float(min_confidence) or direction == Direction.NEUTRAL:
                continue

            strategy_type = select_strategy_type(direction, regime)
            if strategy_type not in (StrategyType.BULL_CALL_SPREAD, StrategyType.BEAR_PUT_SPREAD):
                continue  # single-leg long-premium proxy only; multi-leg spread P&L needs both legs repriced, out of scope for this fixture engine

            is_long, option_type = True, "call" if strategy_type == StrategyType.BULL_CALL_SPREAD else "put"
            strike = round(spot * (1.01 if option_type == "call" else 0.99), 2)
            priced = black_scholes(spot, strike, dte, vol, option_type=option_type)
            trade = BacktestTrade(symbol=symbol, strategy_type=strategy_type.value, entry_day=day, entry_date=str(anchor + timedelta(days=day)), entry_price=priced.price)
            open_trade = {"entry_day": day, "dte": dte, "strike": strike, "option_type": option_type, "is_long": is_long, "entry_price": priced.price, "trade": trade}

    decided = [t for t in trades if t.pnl is not None]
    wins = [t for t in decided if t.pnl > 0]
    losses = [t for t in decided if t.pnl <= 0]
    win_rate = Decimal(len(wins)) / Decimal(len(decided)) if decided else Decimal(0)
    gross_win = sum((t.pnl for t in wins), Decimal(0))
    gross_loss = abs(sum((t.pnl for t in losses), Decimal(0)))
    profit_factor = (gross_win / gross_loss) if gross_loss > 0 else None
    total_return_pct = ((equity - starting_capital) / starting_capital).quantize(Decimal("0.0001"))

    return BacktestResult(
        data_source="simulated_fixture",
        symbols=symbols,
        trades=trades,
        equity_curve=equity_curve,
        starting_capital=starting_capital,
        total_return_pct=total_return_pct,
        win_rate=win_rate.quantize(Decimal("0.0001")),
        profit_factor=profit_factor.quantize(Decimal("0.0001")) if profit_factor is not None else None,
        max_drawdown_pct=_max_drawdown(equity_curve),
        trade_count=len(decided),
        assumptions={
            "data_source": "Deterministic synthetic price series seeded from the symbol name -- NOT real historical prices.",
            "option_pricing": "Black-Scholes repricing at each day's synthetic spot/vol/remaining-DTE.",
            "strategies_covered": "bull_call_spread, bear_put_spread only (single net-premium leg proxy) -- iron_condor/covered_call/cash_secured_put are not backtested by this engine.",
            "slippage": f"{slippage_pct:.2%} of the exit option price, applied against the trader on every exit.",
            "exit_rules": f"profit target {PROFIT_TARGET_PCT_OF_MAX:.0%} of debit, stop loss {STOP_LOSS_PCT_OF_MAX:.0%} of debit, time exit at {MIN_DTE_FOR_TIME_EXIT} DTE.",
        },
    )
