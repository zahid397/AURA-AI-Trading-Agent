"""Tests for RunManager's bounded-timeout + failure-reporting behavior.

Without this, a graph node that hangs (a stuck call, an unexpected await)
leaves `graph.ainvoke()` never returning, `_flush_events` never running, and
the run stranded at whatever status its last-flushed event left it in --
forever, with no signal to Laravel or a frontend polling for a terminal
status. Confirmed live: a manually-triggered run stuck at `running` for
7+ minutes while the frontend correctly kept waiting for a terminal status
that was never going to arrive.
"""
from __future__ import annotations

import asyncio
from datetime import UTC, datetime
from uuid import uuid4

import pytest
from langgraph.checkpoint.memory import InMemorySaver

import app.services.run_manager as run_manager_module
from app.core.config import Settings
from app.risk.policy import RiskPolicyLimits
from app.services.run_manager import RunManager
from app.workflows.state import RunMeta, RunMode, RunTrigger, ScanFilters, SymbolUniverse


class _CapturingEventClient:
    def __init__(self) -> None:
        self.sent: list[dict] = []

    async def send_event(self, event: dict) -> None:
        self.sent.append(event)


class _HangingGraph:
    async def ainvoke(self, *args, **kwargs):
        await asyncio.sleep(999)


def _meta() -> RunMeta:
    now = datetime.now(UTC)
    return RunMeta(
        run_id=uuid4(), trace_id=uuid4(), user_id=uuid4(), mode=RunMode.DEMO, trigger=RunTrigger.MANUAL,
        risk_policy_version=1, created_at=now, updated_at=now,
    )


@pytest.mark.asyncio
async def test_start_run_times_out_and_reports_run_failed_instead_of_hanging_forever(monkeypatch):
    monkeypatch.setattr(run_manager_module, "RUN_TIMEOUT_SECONDS", 0.05)
    monkeypatch.setattr(run_manager_module, "build_graph", lambda *a, **k: _HangingGraph())

    manager = RunManager(Settings(), InMemorySaver())
    captured = _CapturingEventClient()
    manager._events = captured

    meta = _meta()
    universe = SymbolUniverse(symbols=["SPY"], filters=ScanFilters())

    with pytest.raises(asyncio.TimeoutError):
        await manager.start_run(meta, universe, RiskPolicyLimits())

    assert len(captured.sent) == 1
    event = captured.sent[0]
    assert event["type"] == "run.failed"
    assert event["run_id"] == str(meta.run_id)
    assert event["trace_id"] == str(meta.trace_id)
    assert "summary" in event["payload"]


@pytest.mark.asyncio
async def test_resume_run_also_times_out_and_reports_failure(monkeypatch):
    monkeypatch.setattr(run_manager_module, "RUN_TIMEOUT_SECONDS", 0.05)
    monkeypatch.setattr(run_manager_module, "build_graph", lambda *a, **k: _HangingGraph())

    manager = RunManager(Settings(), InMemorySaver())
    captured = _CapturingEventClient()
    manager._events = captured

    meta = _meta()

    async def _fake_get_run_state(run_id):
        return {"meta": meta}

    monkeypatch.setattr(manager, "get_run_state", _fake_get_run_state)

    with pytest.raises(asyncio.TimeoutError):
        await manager.resume_run(meta.run_id, {"approved": True, "approved_by": str(uuid4())}, RiskPolicyLimits())

    assert len(captured.sent) == 1
    assert captured.sent[0]["type"] == "run.failed"


@pytest.mark.asyncio
async def test_report_failure_is_best_effort_and_never_raises_on_its_own():
    manager = RunManager(Settings(), InMemorySaver())

    class _BrokenEventClient:
        async def send_event(self, event: dict) -> None:
            raise RuntimeError("Laravel is unreachable")

    manager._events = _BrokenEventClient()

    # Should swallow the failure silently rather than raising a second,
    # more confusing error on top of the original run failure.
    await manager._report_failure(_meta())
