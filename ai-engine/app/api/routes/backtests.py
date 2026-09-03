"""Backtest lifecycle. The engine itself (`app/backtesting/engine.py`) runs
fast enough (a few hundred in-process day-steps per symbol) that we still
accept-then-run-in-background for API consistency with `/runs`, but results
are kept in a simple in-process dict -- fine for a single-instance hackathon
deployment; a multi-worker deployment would need this in Redis/Postgres
instead (noted in docs/IMPLEMENTATION_STATUS.md)."""
from __future__ import annotations

import asyncio
from decimal import Decimal
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException

from app.api.security import verify_internal_request
from app.backtesting.engine import run_backtest
from app.models.api import BacktestCreateRequest, BacktestResponse

router = APIRouter(tags=["backtests"])

_results: dict[UUID, BacktestResponse] = {}
_background_tasks: set[asyncio.Task] = set()


def _run_and_store(payload: BacktestCreateRequest) -> None:
    result = run_backtest(
        symbols=payload.symbols,
        dte=payload.max_dte,
        min_confidence=Decimal(payload.min_confidence),
        slippage_pct=Decimal(payload.slippage_pct),
        starting_capital=Decimal(payload.starting_capital),
    )
    _results[payload.backtest_id] = BacktestResponse(
        backtest_id=payload.backtest_id,
        status="completed",
        result={
            "data_source": result.data_source,
            "symbols": result.symbols,
            "trade_count": result.trade_count,
            "win_rate": str(result.win_rate),
            "profit_factor": str(result.profit_factor) if result.profit_factor is not None else None,
            "total_return_pct": str(result.total_return_pct),
            "max_drawdown_pct": str(result.max_drawdown_pct),
            "equity_curve": [str(v) for v in result.equity_curve],
            "assumptions": result.assumptions,
            "trades": [
                {
                    "symbol": t.symbol, "strategy_type": t.strategy_type, "entry_date": t.entry_date, "exit_date": t.exit_date,
                    "entry_price": str(t.entry_price), "exit_price": str(t.exit_price) if t.exit_price is not None else None,
                    "pnl": str(t.pnl) if t.pnl is not None else None, "exit_reason": t.exit_reason,
                }
                for t in result.trades
            ],
        },
    )


@router.post("/backtests", response_model=BacktestResponse, dependencies=[Depends(verify_internal_request)])
async def create_backtest(payload: BacktestCreateRequest) -> BacktestResponse:
    _results[payload.backtest_id] = BacktestResponse(backtest_id=payload.backtest_id, status="running")

    async def _runner():
        await asyncio.to_thread(_run_and_store, payload)

    task = asyncio.create_task(_runner())
    _background_tasks.add(task)
    task.add_done_callback(_background_tasks.discard)
    return _results[payload.backtest_id]


@router.get("/backtests/{backtest_id}", response_model=BacktestResponse, dependencies=[Depends(verify_internal_request)])
async def get_backtest(backtest_id: UUID) -> BacktestResponse:
    result = _results.get(backtest_id)
    if result is None:
        raise HTTPException(status_code=404, detail={"error_code": "backtest_not_found"})
    return result
