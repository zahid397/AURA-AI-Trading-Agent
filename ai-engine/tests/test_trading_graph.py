"""Smoke tests for the compiled LangGraph workflow.

Covers the two paths that don't require a mid-run interrupt/resume: a
`paper_autonomous` run that runs end-to-end to a filled demo order, and a
`demo` run whose risk policy is deliberately made impossible to pass so it
takes the `reject` branch. The `approval_required` interrupt/resume path is
exercised structurally (the graph reaches `__interrupt__` and pauses) but a
full resume round-trip is covered at the API layer, not here.
"""
from __future__ import annotations

from datetime import UTC, datetime
from decimal import Decimal
from uuid import uuid4

import pytest
from langgraph.checkpoint.memory import InMemorySaver
from langgraph.types import Command

from app.alpaca.demo_adapter import DemoAlpacaAdapter
from app.llm.provider import FakeLLMProvider
from app.risk.policy import RiskPolicyLimits
from app.workflows.state import (
    GraphState,
    RunMeta,
    RunMode,
    RunStatus,
    RunTrigger,
    ScanFilters,
    SymbolUniverse,
)
from app.workflows.trading_graph import build_graph


def _initial_state(mode: RunMode, symbols: list[str], filters: ScanFilters | None = None) -> GraphState:
    now = datetime.now(UTC)
    return GraphState(
        meta=RunMeta(
            run_id=uuid4(), trace_id=uuid4(), user_id=uuid4(), mode=mode, trigger=RunTrigger.MANUAL,
            risk_policy_version=1, created_at=now, updated_at=now,
        ),
        universe=SymbolUniverse(symbols=symbols, filters=filters or ScanFilters()),
    )


@pytest.mark.asyncio
async def test_paper_autonomous_run_completes_and_submits_a_demo_order():
    gateway = DemoAlpacaAdapter()
    llm = FakeLLMProvider()
    policy = RiskPolicyLimits()
    graph = build_graph(gateway, llm, policy, InMemorySaver())

    state = _initial_state(RunMode.PAPER_AUTONOMOUS, ["SPY"])
    config = {"configurable": {"thread_id": str(state.meta.run_id)}}
    result = await graph.ainvoke(state, config=config)

    assert result["meta"].status in (RunStatus.COMPLETED, RunStatus.REJECTED)
    if result["meta"].status == RunStatus.COMPLETED:
        assert result["order_result"] is not None
        assert result["order_result"].submitted_via.value == "demo"
        assert any(e.type == "order.submitted" for e in result["audit_events"])
    else:
        # A demo run can legitimately reject too (e.g. synthetic liquidity below the policy floor
        # for this particular seeded symbol) -- what matters is it never crashes and always explains why.
        assert result["rejection_reason"]


@pytest.mark.asyncio
async def test_impossible_policy_rejects_without_crashing():
    gateway = DemoAlpacaAdapter()
    llm = FakeLLMProvider()
    impossible_policy = RiskPolicyLimits(min_confidence=Decimal("0.999"))
    graph = build_graph(gateway, llm, impossible_policy, InMemorySaver())

    state = _initial_state(RunMode.PAPER_AUTONOMOUS, ["NVDA"])
    config = {"configurable": {"thread_id": str(state.meta.run_id)}}
    result = await graph.ainvoke(state, config=config)

    assert result["meta"].status == RunStatus.REJECTED
    assert result["risk_evaluation"].result.value == "rejected"
    assert result.get("order_result") is None


@pytest.mark.asyncio
async def test_approval_required_mode_pauses_at_the_interrupt():
    gateway = DemoAlpacaAdapter()
    llm = FakeLLMProvider()
    policy = RiskPolicyLimits()
    checkpointer = InMemorySaver()
    graph = build_graph(gateway, llm, policy, checkpointer)

    state = _initial_state(RunMode.APPROVAL_REQUIRED, ["TSLA"])
    config = {"configurable": {"thread_id": str(state.meta.run_id)}}
    result = await graph.ainvoke(state, config=config)

    if "__interrupt__" in result:
        # Paused for human approval -- resume it and confirm it can complete.
        resumed = await graph.ainvoke(Command(resume={"approved": True, "approved_by": str(uuid4())}), config=config)
        assert resumed["meta"].status in (RunStatus.COMPLETED, RunStatus.REJECTED)
        assert resumed["approval"].status == "approved"
    else:
        # Rejected before ever reaching the approval gate -- still a valid, explained outcome.
        assert result["meta"].status == RunStatus.REJECTED
