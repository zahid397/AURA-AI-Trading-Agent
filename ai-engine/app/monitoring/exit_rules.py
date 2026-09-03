"""Shared exit-rule evaluation for open options positions.

The same three rules apply whether a position is being watched live (the
`reconcile_and_monitor` graph node, and the scheduled "position monitoring"
task Laravel triggers) or replayed in the backtest engine -- extracted here
so both paths can never silently drift apart.
"""
from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal

PROFIT_TARGET_PCT_OF_MAX = Decimal("0.50")
STOP_LOSS_PCT_OF_MAX = Decimal("1.00")
MIN_DTE_FOR_TIME_EXIT = 2


@dataclass(frozen=True)
class ExitCheck:
    should_exit: bool
    reason: str | None
    unrealized_pnl: Decimal


def describe_exit_conditions(max_profit: Decimal) -> list[str]:
    target = (max_profit * PROFIT_TARGET_PCT_OF_MAX).quantize(Decimal("0.01"))
    return [
        f"Profit target: {PROFIT_TARGET_PCT_OF_MAX:.0%} of max profit (~${target})",
        f"Stop loss: {STOP_LOSS_PCT_OF_MAX:.0%} of max loss reached",
        f"Time exit: {MIN_DTE_FOR_TIME_EXIT} days to expiration or fewer",
    ]


def check_exit(unrealized_pnl: Decimal, max_profit: Decimal, max_loss: Decimal, remaining_dte: int) -> ExitCheck:
    profit_target = max_profit * PROFIT_TARGET_PCT_OF_MAX
    loss_stop = max_loss * STOP_LOSS_PCT_OF_MAX

    if unrealized_pnl >= profit_target > 0:
        return ExitCheck(True, "profit_target", unrealized_pnl)
    if unrealized_pnl <= -loss_stop < 0:
        return ExitCheck(True, "stop_loss", unrealized_pnl)
    if remaining_dte <= MIN_DTE_FOR_TIME_EXIT:
        return ExitCheck(True, "time_exit", unrealized_pnl)
    return ExitCheck(False, None, unrealized_pnl)
