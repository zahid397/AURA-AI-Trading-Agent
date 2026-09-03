"""HTTP-layer request/response schemas for the internal Laravel<->FastAPI API.

Deliberately separate from `app.workflows.state` (the graph's internal
contract) -- the wire format here is what `docs/architecture.md` documents,
and keeping it as its own module means the graph's state shape can evolve
without silently changing the HTTP contract Laravel depends on.
"""
from __future__ import annotations

from typing import Any, Literal
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field

from app.workflows.state import RunMode, RunTrigger, ScanFilters


class RunCreateRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    run_id: UUID
    trace_id: UUID | None = None
    user_id: UUID
    mode: RunMode
    trigger: RunTrigger
    symbols: list[str] = Field(min_length=1)
    scan_filters: ScanFilters = Field(default_factory=ScanFilters)
    risk_policy_limits: dict[str, Any] = Field(default_factory=dict)
    risk_policy_version: int = 1
    alpaca_connection_id: UUID | None = None


class RunAcceptedResponse(BaseModel):
    accepted: bool
    run_id: UUID


class RunStateResponse(BaseModel):
    run_id: UUID
    found: bool
    status: str | None = None
    awaiting_approval: bool = False
    state: dict[str, Any] | None = None


class RunResumeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    decision: Literal["approved", "rejected"]
    approved_by: UUID
    notes: str | None = None
    risk_policy_limits: dict[str, Any] = Field(default_factory=dict)


class RunCancelResponse(BaseModel):
    run_id: UUID
    cancel_requested: bool
    note: str


class BacktestCreateRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    backtest_id: UUID
    symbols: list[str] = Field(min_length=1)
    strategy_types: list[str] = Field(default_factory=list)
    start_date: str
    end_date: str
    starting_capital: str = "100000.00"
    min_dte: int = 7
    max_dte: int = 45
    min_confidence: str = "0.55"
    slippage_pct: str = "0.02"


class BacktestResponse(BaseModel):
    backtest_id: UUID
    status: str
    result: dict[str, Any] | None = None


class HealthResponse(BaseModel):
    status: Literal["ok"]
    service: str = "aura-ai-engine"


class ReadyResponse(BaseModel):
    ready: bool
    checks: dict[str, bool]
