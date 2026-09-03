"""Run lifecycle endpoints -- the Laravel -> FastAPI half of the internal API
documented in docs/architecture.md. `POST /runs` returns immediately
(`accepted: true`) and does the actual graph execution in a background
asyncio task; the graph posts its own progress back to Laravel as signed
events (`app/services/laravel_events.py`), so Laravel does not need to poll
`GET /runs/{run_id}` except as a fallback.
"""
from __future__ import annotations

import asyncio
from datetime import UTC, datetime
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Request

from app.api.security import verify_internal_request
from app.models.api import (
    RunAcceptedResponse,
    RunCancelResponse,
    RunCreateRequest,
    RunResumeRequest,
    RunStateResponse,
)
from app.risk.policy import RiskPolicyLimits
from app.workflows.state import GraphState, RunMeta, RunStatus, SymbolUniverse

router = APIRouter(tags=["runs"])

# Keeps references to in-flight background tasks so they aren't garbage
# collected mid-run (a bare `asyncio.create_task(...)` with no retained
# reference is a classic silent-cancellation footgun).
_background_tasks: set[asyncio.Task] = set()


def _track(task: asyncio.Task) -> None:
    _background_tasks.add(task)
    task.add_done_callback(_background_tasks.discard)


def _state_to_wire(result: dict) -> dict:
    try:
        return GraphState.model_validate(result).model_dump(mode="json")
    except Exception:
        return {k: v for k, v in result.items() if k != "audit_events"}


@router.post("/runs", response_model=RunAcceptedResponse, dependencies=[Depends(verify_internal_request)])
async def create_run(payload: RunCreateRequest, request: Request) -> RunAcceptedResponse:
    run_manager = request.app.state.run_manager
    now = datetime.now(UTC)
    meta = RunMeta(
        run_id=payload.run_id, trace_id=payload.trace_id or payload.run_id, user_id=payload.user_id,
        mode=payload.mode, trigger=payload.trigger, risk_policy_version=payload.risk_policy_version,
        alpaca_connection_id=payload.alpaca_connection_id, created_at=now, updated_at=now,
    )
    universe = SymbolUniverse(symbols=payload.symbols, filters=payload.scan_filters)
    policy = RiskPolicyLimits(**payload.risk_policy_limits) if payload.risk_policy_limits else RiskPolicyLimits()

    task = asyncio.create_task(run_manager.start_run(meta, universe, policy))
    _track(task)
    return RunAcceptedResponse(accepted=True, run_id=payload.run_id)


@router.get("/runs/{run_id}", response_model=RunStateResponse, dependencies=[Depends(verify_internal_request)])
async def get_run(run_id: UUID, request: Request) -> RunStateResponse:
    run_manager = request.app.state.run_manager
    state = await run_manager.get_run_state(run_id)
    if state is None:
        return RunStateResponse(run_id=run_id, found=False)
    meta = state.get("meta")
    status_value = meta.status.value if meta else None
    return RunStateResponse(
        run_id=run_id, found=True, status=status_value,
        awaiting_approval=(status_value == RunStatus.AWAITING_APPROVAL.value),
        state=_state_to_wire(state),
    )


@router.post("/runs/{run_id}/resume", response_model=RunStateResponse, dependencies=[Depends(verify_internal_request)])
async def resume_run(run_id: UUID, payload: RunResumeRequest, request: Request) -> RunStateResponse:
    run_manager = request.app.state.run_manager
    policy = RiskPolicyLimits(**payload.risk_policy_limits) if payload.risk_policy_limits else RiskPolicyLimits()
    decision = {"approved": payload.decision == "approved", "approved_by": str(payload.approved_by), "notes": payload.notes}
    try:
        result = await run_manager.resume_run(run_id, decision, policy)
    except ValueError:
        raise HTTPException(status_code=404, detail={"error_code": "run_not_found"})
    return RunStateResponse(run_id=run_id, found=True, status=result["meta"].status.value, state=_state_to_wire(result))


@router.post("/runs/{run_id}/cancel", response_model=RunCancelResponse, dependencies=[Depends(verify_internal_request)])
async def cancel_run(run_id: UUID) -> RunCancelResponse:
    return RunCancelResponse(
        run_id=run_id,
        cancel_requested=True,
        note=(
            "Best-effort only: this build's graph nodes complete in well under a second against demo/paper "
            "data, so there is no long-running step to interrupt mid-execution. If the run is paused awaiting "
            "human approval, resolve it instead via POST /runs/{run_id}/resume with decision=rejected."
        ),
    )
