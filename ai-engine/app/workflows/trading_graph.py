"""The real LangGraph state machine for a single AURA trading run.

Nodes are closures over an injected `AlpacaGateway` and `LLMProvider` (built
by `build_graph`) rather than reaching for globals -- this keeps every node
a pure function of `(state) -> partial_update` that can be unit tested
without patching module state, and lets each run use the gateway/provider
appropriate for that user's connection.

Human approval is a real LangGraph interrupt (`langgraph.types.interrupt`),
not a polling loop: the `await_approval` node call blocks the graph at a
checkpoint until something calls `graph.ainvoke(Command(resume=...), config)`
with the same `thread_id` (the run's `run_id`). See `app/api/routes/runs.py`
for where that resume call is wired to Laravel's
`POST /internal/v1/runs/{run_id}/resume`.

Audit events are appended to `state.audit_events` by the nodes themselves
(pure, no I/O) -- the API layer in `app/api/routes/runs.py` is responsible
for actually POSTing them to Laravel after each step, so this module stays
network-free and trivially testable.
"""
from __future__ import annotations

from datetime import UTC, datetime
from decimal import Decimal
from uuid import uuid4

from langgraph.checkpoint.base import BaseCheckpointSaver
from langgraph.graph import END, START, StateGraph
from langgraph.graph.state import CompiledStateGraph
from langgraph.types import interrupt

from app.agents import execution as execution_agent
from app.agents import learning as learning_agent
from app.agents import market_analyst, options_strategy, risk_guardian
from app.alpaca.gateway import AlpacaGateway
from app.llm.provider import LLMProvider
from app.monitoring.exit_rules import describe_exit_conditions
from app.risk.engine import AccountRiskContext
from app.risk.policy import RiskPolicyLimits
from app.workflows.state import (
    ApprovalState,
    AuditEvent,
    GraphState,
    MonitoringState,
    RiskResult,
    RunError,
    RunMode,
    RunStatus,
)


def _audit(state: GraphState, event_type: str, payload: dict) -> AuditEvent:
    return AuditEvent(event_id=uuid4(), type=event_type, payload=payload, occurred_at=datetime.now(UTC))


def _leg_to_wire(leg, key: str) -> dict:
    """Serializes an `OptionLeg` for an event payload. Laravel's two leg
    consumers use different key names for the same buy/sell field --
    `strategy.proposed` calls it `action`, `order.submitted` calls it
    `side` -- pass `key` to match whichever this payload needs."""
    return {
        key: leg.side.value,
        "occ_symbol": leg.occ_symbol,
        "underlying": leg.underlying,
        "option_type": leg.option_type.value,
        "strike": str(leg.strike),
        "expiration": leg.expiration.isoformat(),
        "position_intent": leg.position_intent.value,
        "ratio_qty": leg.ratio_qty,
        "quote_bid": str(leg.quote_bid),
        "quote_ask": str(leg.quote_ask),
        "quote_time": leg.quote_time.isoformat(),
        "delta": str(leg.delta) if leg.delta is not None else None,
        "gamma": str(leg.gamma) if leg.gamma is not None else None,
        "theta": str(leg.theta) if leg.theta is not None else None,
        "vega": str(leg.vega) if leg.vega is not None else None,
        "open_interest": leg.open_interest,
        "volume": leg.volume,
    }


def _feature_snapshot(features) -> dict:
    return {
        "ema_trend": features.ema_trend.value,
        "rsi_14": str(features.rsi_14),
        "macd": str(features.macd),
        "macd_signal": str(features.macd_signal),
        "atr_14": str(features.atr_14),
        "realized_vol_annualized": str(features.realized_vol_annualized),
        "relative_volume": str(features.relative_volume),
        "warnings": features.warnings,
    }


async def _build_account_context(gateway: AlpacaGateway) -> AccountRiskContext:
    account = await gateway.get_account()
    clock = await gateway.get_clock()
    positions = await gateway.get_positions()
    exposure_by_symbol: dict[str, Decimal] = {}
    total_options_exposure = Decimal(0)
    for p in positions:
        if p.asset_class == "us_option":
            exposure_by_symbol[p.symbol] = exposure_by_symbol.get(p.symbol, Decimal(0)) + abs(p.market_value)
            total_options_exposure += abs(p.market_value)
    return AccountRiskContext(
        equity=account.equity,
        buying_power=account.buying_power,
        daily_realized_pl=account.daily_realized_pl,
        daily_unrealized_pl=account.daily_unrealized_pl,
        peak_equity_30d=account.equity,  # conservative: without a portfolio-history call, assume no drawdown cushion
        open_positions_count=len(positions),
        open_options_exposure=total_options_exposure,
        open_exposure_by_symbol=exposure_by_symbol,
        kill_switch_engaged=False,  # kill switch is Laravel-owned policy state, passed in via risk_policy_version lookup in a fuller build
        market_is_open=clock.is_open,
    )


def build_graph(
    gateway: AlpacaGateway,
    llm: LLMProvider,
    policy: RiskPolicyLimits,
    checkpointer: BaseCheckpointSaver,
) -> CompiledStateGraph:
    async def validate_environment(state: GraphState) -> dict:
        caps = await gateway.get_capabilities()
        events = [*state.audit_events, _audit(state, "run.started", {"mode": state.meta.mode.value, "symbols": state.universe.symbols})]
        if not caps.paper_verified:
            events.append(_audit(state, "run.failed", {"summary": "Paper trading could not be verified; refusing to run."}))
            return {
                "capabilities": caps,
                "audit_events": events,
                "meta": state.meta.model_copy(update={"status": RunStatus.FAILED, "updated_at": datetime.now(UTC)}),
                "errors": [*state.errors, RunError(node="validate_environment", message="Paper trading could not be verified.", retryable=False, occurred_at=datetime.now(UTC))],
            }
        return {"capabilities": caps, "audit_events": events, "meta": state.meta.model_copy(update={"status": RunStatus.RUNNING, "updated_at": datetime.now(UTC)})}

    async def load_market_data(state: GraphState) -> dict:
        market_data, features = dict(state.market_data), dict(state.features)
        for symbol in state.universe.symbols:
            snapshot = await gateway.get_bars(symbol)
            market_data[symbol] = snapshot
            features[symbol] = market_analyst.compute_features(symbol, snapshot)
        return {"market_data": market_data, "features": features}

    async def run_market_analyst(state: GraphState) -> dict:
        theses = dict(state.theses)
        events = list(state.audit_events)
        for symbol in state.universe.symbols:
            snapshot, feats = state.market_data.get(symbol), state.features.get(symbol)
            if snapshot is None or feats is None:
                continue
            thesis = await market_analyst.analyze(symbol, snapshot, feats, llm)
            theses[symbol] = thesis
            events.append(_audit(state, "thesis.generated", {
                "symbol": symbol,
                "direction": thesis.direction.value,
                "thesis": thesis.thesis,
                "evidence": [
                    {"label": e.label, "value": e.value, "observed_at": e.observed_at.isoformat(), "source": e.source}
                    for e in thesis.evidence
                ],
                "confidence": str(thesis.confidence),
                "model_provider": "fake" if llm.name == "fake-deterministic-v1" else "anthropic",
                "model_name": llm.name,
                "prompt_version": "v1",
                "feature_snapshot": _feature_snapshot(feats),
            }))
        return {"theses": theses, "audit_events": events}

    async def run_options_strategy(state: GraphState) -> dict:
        candidates = list(state.candidates)
        events = list(state.audit_events)
        for symbol, thesis in state.theses.items():
            snapshot = state.market_data.get(symbol)
            if snapshot is None:
                continue
            candidate = await options_strategy.propose(symbol, snapshot.last_price, thesis, state.features[symbol], gateway, state.universe.filters)
            if candidate is not None:
                candidates.append(candidate)
        ranked = options_strategy.rank(candidates)
        for i, c in enumerate(ranked):
            confidence = state.theses[c.symbol].confidence if c.symbol in state.theses else Decimal("0.5")
            events.append(_audit(state, "strategy.proposed", {
                "strategy_id": str(c.candidate_id),
                "symbol": c.symbol,
                "strategy_type": c.strategy_type.value,
                "regime": c.regime.value,
                "thesis": c.thesis,
                "confidence": str(confidence),
                "net_debit": str(c.net_debit) if c.net_debit is not None else None,
                "net_credit": str(c.net_credit) if c.net_credit is not None else None,
                "max_profit": str(c.max_profit),
                "max_loss": str(c.max_loss),
                "breakevens": [str(b) for b in c.breakevens],
                "probability_of_profit": str(c.probability_of_profit),
                "liquidity_score": str(c.liquidity_score),
                "score": str(c.score),
                "score_breakdown": {k: str(v) for k, v in c.score_components.items()},
                "quote_timestamp": c.quote_timestamp.isoformat(),
                "dte": c.dte,
                "rank": c.rank,
                "selected": i == 0,
                "legs": [_leg_to_wire(leg, "action") for leg in c.legs],
            }))
        selected = ranked[0] if ranked else None
        return {"candidates": ranked, "selected_strategy": selected, "audit_events": events}

    async def run_risk_guardian(state: GraphState) -> dict:
        if state.selected_strategy is None:
            return {
                "rejection_reason": "No viable strategy candidate survived scanning + selection.",
                "meta": state.meta.model_copy(update={"status": RunStatus.REJECTED}),
            }
        account = await _build_account_context(gateway)
        quote_age = max(0, int((datetime.now(UTC) - state.selected_strategy.quote_timestamp).total_seconds()))
        duplicate = False  # duplicate-in-flight detection is Laravel's job (it owns trade_proposals); ai-engine assumes false per run
        evaluation, summary = await risk_guardian.review(
            state.selected_strategy, account, state.capabilities, policy, state.meta.risk_policy_version,
            state.theses[state.selected_strategy.symbol].confidence, quote_age, duplicate, llm,
        )
        events = [*state.audit_events, _audit(state, "risk.evaluated", {
            "strategy_id": str(state.selected_strategy.candidate_id),
            # `evaluation_id` doubles as Laravel's trade_proposal id -- there is
            # exactly one proposal per risk evaluation in this build, so reusing
            # the id avoids inventing a second identifier that would need its
            # own round trip to reconcile.
            "trade_proposal_id": str(evaluation.evaluation_id),
            "risk_policy_version": evaluation.risk_policy_version,
            "result": evaluation.result.value,
            "risk_score": str(evaluation.risk_score / Decimal(100)),  # engine reports 0-100; Laravel's column is a 0-1 fraction
            "rule_results": [
                {
                    "rule_key": r.rule_key, "description": r.description,
                    "observed_value": r.observed_value, "limit_value": r.limit_value,
                    "passed": r.passed, "severity": r.severity.value, "explanation": r.explanation,
                }
                for r in evaluation.rule_results
            ],
            "summary": summary,
        })]
        return {"risk_evaluation": evaluation, "audit_events": events}

    def route_after_risk(state: GraphState) -> str:
        if state.risk_evaluation is None or state.risk_evaluation.result == RiskResult.REJECTED:
            return "reject"
        if state.meta.mode == RunMode.OBSERVE:
            return "observed_complete"
        if state.meta.mode in (RunMode.PAPER_AUTONOMOUS, RunMode.DEMO) and state.risk_evaluation.result == RiskResult.APPROVED:
            return "execution"
        return "await_approval"

    async def mark_awaiting_approval(state: GraphState) -> dict:
        """Commits `status = AWAITING_APPROVAL` to the checkpoint *before*
        `await_approval` calls `interrupt()`. This has to be a separate node:
        a node's state update only takes effect when it returns, but
        `interrupt()` pauses execution mid-function without returning, so
        anything the `await_approval` node itself tried to set beforehand
        would never actually be committed -- `GET /runs/{run_id}` would keep
        reporting the pre-interrupt status forever."""
        return {"meta": state.meta.model_copy(update={"status": RunStatus.AWAITING_APPROVAL, "updated_at": datetime.now(UTC)})}

    async def await_approval(state: GraphState) -> dict:
        decision = interrupt({
            "run_id": str(state.meta.run_id),
            "symbol": state.selected_strategy.symbol if state.selected_strategy else None,
            "strategy_type": state.selected_strategy.strategy_type.value if state.selected_strategy else None,
            "risk_result": state.risk_evaluation.result.value if state.risk_evaluation else None,
        })
        approved = bool(decision.get("approved"))
        approval = ApprovalState(
            required=True,
            status="approved" if approved else "rejected",
            approved_by=decision.get("approved_by"),
            decided_at=datetime.now(UTC),
            notes=decision.get("notes"),
        )
        events = [*state.audit_events, _audit(state, "approval.requested", {"decision": approval.status})]
        return {"approval": approval, "audit_events": events}

    def route_after_approval(state: GraphState) -> str:
        return "execution" if state.approval and state.approval.status == "approved" else "reject"

    async def run_execution(state: GraphState) -> dict:
        order_request = execution_agent.build_order_request(state.selected_strategy, str(state.meta.run_id))
        result = await execution_agent.execute(order_request, gateway)
        events = [*state.audit_events, _audit(state, "order.submitted", {
            # Same id `risk.evaluated` sent as `trade_proposal_id` -- Laravel
            # already has a TradeProposal row keyed on it.
            "trade_proposal_id": str(state.risk_evaluation.evaluation_id),
            "broker_order_id": result.broker_order_id,
            "client_order_id": result.client_order_id,
            "idempotency_key": order_request.idempotency_key,
            "order_class": order_request.order_class,
            "symbol": state.selected_strategy.symbol,
            "qty": order_request.qty,
            "submitted_via": result.submitted_via.value,
            "raw_response": result.raw_response_sanitized,
            "legs": [_leg_to_wire(leg, "side") for leg in order_request.legs],
        })]
        return {"order_request": order_request, "order_result": result, "audit_events": events}

    async def reconcile_and_monitor(state: GraphState) -> dict:
        exit_conditions = (
            describe_exit_conditions(state.selected_strategy.max_profit)
            if state.selected_strategy is not None
            else []
        )
        monitoring = MonitoringState(status="monitoring", exit_conditions=exit_conditions, last_checked_at=datetime.now(UTC))
        # A full `position.updated` event needs per-leg fill data (avg entry
        # price, quantity, opened_at) this build's demo/paper fill response
        # doesn't break out -- only the account-level `portfolio.updated`
        # aggregate is sent here; per-position rows remain Laravel's own
        # `TradeProposal`/`Order` records rather than a duplicated `Position`.
        account = await gateway.get_account()
        events = [*state.audit_events, _audit(state, "portfolio.updated", {
            "user_id": str(state.meta.user_id),
            "equity": str(account.equity),
            "cash": str(account.cash),
            "buying_power": str(account.buying_power),
            "unrealized_pl": str(account.daily_unrealized_pl),
            "realized_pl": str(account.daily_realized_pl),
            "options_exposure": str(state.selected_strategy.max_loss) if state.selected_strategy else "0",
        })]
        return {"monitoring": monitoring, "audit_events": events}

    async def run_learning(state: GraphState) -> dict:
        recommendation = learning_agent.analyze([])  # no closed-trade history is available within a single run
        events = list(state.audit_events)
        if recommendation is not None:
            events.append(_audit(state, "learning.recommendation", {
                "message": f"Learning recommendation {recommendation.recommendation_id}: {recommendation.expected_effect}",
                "context": {"recommendation_id": str(recommendation.recommendation_id), "proposed_changes": recommendation.proposed_changes},
            }))
        summary = (
            f"{state.selected_strategy.strategy_type.value} on {state.selected_strategy.symbol} "
            f"executed via {state.order_result.submitted_via.value}."
            if state.selected_strategy and state.order_result else "Run completed."
        )
        events.append(_audit(state, "run.completed", {"summary": summary}))
        return {
            "learning": recommendation,
            "audit_events": events,
            "meta": state.meta.model_copy(update={"status": RunStatus.COMPLETED, "updated_at": datetime.now(UTC)}),
        }

    async def reject(state: GraphState) -> dict:
        reason = state.rejection_reason or (
            "; ".join(r.explanation for r in state.risk_evaluation.rule_results if not r.passed) if state.risk_evaluation else "Rejected."
        )
        # `run.rejected` (a deterministic risk gate declined the trade) is a
        # distinct, expected business outcome -- deliberately not
        # `run.failed`, which Laravel reserves for genuine technical errors.
        events = [*state.audit_events, _audit(state, "run.rejected", {"summary": reason})]
        return {"rejection_reason": reason, "audit_events": events, "meta": state.meta.model_copy(update={"status": RunStatus.REJECTED, "updated_at": datetime.now(UTC)})}

    async def observed_complete(state: GraphState) -> dict:
        events = [*state.audit_events, _audit(state, "run.completed", {"summary": "Analysis only; observe mode never submits an order."})]
        return {"audit_events": events, "meta": state.meta.model_copy(update={"status": RunStatus.COMPLETED, "updated_at": datetime.now(UTC)})}

    graph = StateGraph(GraphState)
    graph.add_node("validate_environment", validate_environment)
    graph.add_node("load_market_data", load_market_data)
    graph.add_node("market_analyst", run_market_analyst)
    graph.add_node("options_strategy", run_options_strategy)
    graph.add_node("risk_guardian", run_risk_guardian)
    graph.add_node("mark_awaiting_approval", mark_awaiting_approval)
    graph.add_node("await_approval", await_approval)
    graph.add_node("execution", run_execution)
    graph.add_node("reconcile_and_monitor", reconcile_and_monitor)
    graph.add_node("learning", run_learning)
    graph.add_node("reject", reject)
    graph.add_node("observed_complete", observed_complete)

    graph.add_edge(START, "validate_environment")
    graph.add_conditional_edges(
        "validate_environment",
        lambda s: "ok" if s.meta.status != RunStatus.FAILED else "failed",
        {"ok": "load_market_data", "failed": END},
    )
    graph.add_edge("load_market_data", "market_analyst")
    graph.add_edge("market_analyst", "options_strategy")
    graph.add_conditional_edges(
        "options_strategy",
        lambda s: "ok" if s.selected_strategy is not None else "reject",
        {"ok": "risk_guardian", "reject": "reject"},
    )
    graph.add_conditional_edges(
        "risk_guardian", route_after_risk,
        {"reject": "reject", "observed_complete": "observed_complete", "execution": "execution", "await_approval": "mark_awaiting_approval"},
    )
    graph.add_edge("mark_awaiting_approval", "await_approval")
    graph.add_conditional_edges("await_approval", route_after_approval, {"execution": "execution", "reject": "reject"})
    graph.add_edge("execution", "reconcile_and_monitor")
    graph.add_edge("reconcile_and_monitor", "learning")
    graph.add_edge("learning", END)
    graph.add_edge("reject", END)
    graph.add_edge("observed_complete", END)

    return graph.compile(checkpointer=checkpointer)
