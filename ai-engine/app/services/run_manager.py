"""Ties together gateway selection, the compiled graph, and event delivery.

This is the one place that decides *which* `AlpacaGateway` a run actually
uses: `demo` mode (or missing Alpaca credentials) always gets the
network-free `DemoAlpacaAdapter`; anything else gets the real paper `alpaca_api`
adapter. Resuming a paused run re-derives the same choice from the
checkpointed `meta.mode` rather than trusting a caller-supplied value, so a
resume can never accidentally switch a demo run onto the real API.

All audit events accumulated in `state.audit_events` are (re-)sent to
Laravel after every `ainvoke` call, including on resume. Re-sending earlier
events is deliberate, not a bug: Laravel de-duplicates by `event_id`, so a
resumed run simply re-confirms events it already has -- that's cheaper to
reason about than tracking a "high-water mark" of what was already flushed.
"""
from __future__ import annotations

import asyncio
from datetime import UTC, datetime
from typing import Any
from uuid import UUID, uuid4

from langgraph.checkpoint.base import BaseCheckpointSaver
from langgraph.types import Command

from app.alpaca.demo_adapter import DemoAlpacaAdapter
from app.alpaca.gateway import AlpacaGateway
from app.core.config import Settings
from app.llm.provider import get_llm_provider
from app.risk.policy import RiskPolicyLimits
from app.services.laravel_events import LaravelEventClient, audit_event_to_wire
from app.workflows.state import GraphState, RunMeta, RunMode, SymbolUniverse
from app.workflows.trading_graph import build_graph

# Generous for this build (graph steps normally complete in well under a
# second against demo/paper data -- see docs/IMPLEMENTATION_STATUS.md), but
# bounded: without this, a node that hangs (a stuck call, an unexpected
# await) leaves `graph.ainvoke()` never returning, `_flush_events` never
# running, and the run stranded at whatever status its last-flushed event
# left it in -- forever, with no user-facing signal (confirmed live: a run
# stuck at `running` for 7+ minutes with the frontend polling it the whole
# time, since polling correctly waits for a terminal status that was never
# going to arrive).
RUN_TIMEOUT_SECONDS = 60


class RunManager:
    def __init__(self, settings: Settings, checkpointer: BaseCheckpointSaver):
        self._settings = settings
        self._checkpointer = checkpointer
        self._llm = get_llm_provider(settings.llm_provider, settings.llm_api_key, settings.llm_model)
        self._events = LaravelEventClient(settings.laravel_internal_base_url, settings.internal_signing_secret)

    def _gateway_for_mode(self, mode: RunMode) -> AlpacaGateway:
        has_credentials = bool(self._settings.alpaca_api_key and self._settings.alpaca_secret_key)
        if mode == RunMode.DEMO or not has_credentials:
            return DemoAlpacaAdapter()
        from app.alpaca.trading_api import (
            AlpacaApiAdapter,  # local import: alpaca-py is only needed on this path
        )

        return AlpacaApiAdapter(self._settings.alpaca_api_key, self._settings.alpaca_secret_key)

    async def _flush_events(self, result: dict[str, Any], run_id: UUID, trace_id: UUID) -> None:
        events = result.get("audit_events", [])
        for event in events:
            try:
                await self._events.send_event(audit_event_to_wire(event, str(run_id), str(trace_id)))
            except Exception:
                # Best-effort delivery -- a Laravel outage must not fail an in-progress trading run.
                # The full event list is reconstructable from this run's LangGraph checkpoint if needed.
                continue

    async def start_run(self, meta: RunMeta, universe: SymbolUniverse, policy: RiskPolicyLimits) -> dict[str, Any]:
        gateway = self._gateway_for_mode(meta.mode)
        graph = build_graph(gateway, self._llm, policy, self._checkpointer)
        state = GraphState(meta=meta, universe=universe)
        config = {"configurable": {"thread_id": str(meta.run_id)}}
        try:
            result = await asyncio.wait_for(graph.ainvoke(state, config=config), timeout=RUN_TIMEOUT_SECONDS)
        except Exception:
            await self._report_failure(meta)
            raise
        await self._flush_events(result, meta.run_id, meta.trace_id)
        return result

    async def _report_failure(self, meta: RunMeta) -> None:
        """If the graph itself times out or raises, nothing else will ever post a
        run.completed/run.failed event for this run -- it would otherwise stay
        stranded at whatever status its last-flushed event left it in (e.g.
        `running`, from an earlier run.started). Report the failure directly so
        Laravel -- and a frontend polling GET /agent-runs/{id} for a terminal
        status -- aren't left waiting on a run that will never resolve.
        Best-effort, same philosophy as `_flush_events`: a Laravel outage on top
        of an already-failed run must not raise a second, more confusing error.
        """
        try:
            await self._events.send_event({
                "event_id": str(uuid4()),
                "run_id": str(meta.run_id),
                "trace_id": str(meta.trace_id),
                "type": "run.failed",
                "payload": {"summary": "The run did not complete (internal error or timeout)."},
                "occurred_at": datetime.now(UTC).isoformat(),
            })
        except Exception:
            pass

    async def get_run_state(self, run_id: UUID) -> dict[str, Any] | None:
        peek_graph = build_graph(DemoAlpacaAdapter(), self._llm, RiskPolicyLimits(), self._checkpointer)
        snapshot = await peek_graph.aget_state({"configurable": {"thread_id": str(run_id)}})
        return dict(snapshot.values) if snapshot and snapshot.values else None

    async def resume_run(self, run_id: UUID, decision: dict[str, Any], policy: RiskPolicyLimits) -> dict[str, Any]:
        current = await self.get_run_state(run_id)
        if current is None:
            raise ValueError(f"No run found for run_id={run_id}")
        meta: RunMeta = current["meta"]
        gateway = self._gateway_for_mode(meta.mode)
        graph = build_graph(gateway, self._llm, policy, self._checkpointer)
        config = {"configurable": {"thread_id": str(run_id)}}
        try:
            result = await asyncio.wait_for(graph.ainvoke(Command(resume=decision), config=config), timeout=RUN_TIMEOUT_SECONDS)
        except Exception:
            await self._report_failure(meta)
            raise
        await self._flush_events(result, meta.run_id, meta.trace_id)
        return result
