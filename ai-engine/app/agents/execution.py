"""Execution Agent.

Only ever called after a `RiskEvaluation.result == APPROVED` (autonomous) or
an explicit human approval (`ApprovalState.status == "approved"`) -- the
caller (`trading_graph.py`) enforces that; this module trusts its inputs but
still re-checks for an existing order under the same `client_order_id`
before submitting, so a retried graph step can never create a duplicate
broker order. `submitted_via` always reflects `gateway.channel_name`, never
a hard-coded guess -- the audit trail must never claim MCP handled an order
that the direct API actually placed, or vice versa.
"""
from __future__ import annotations

import hashlib
from datetime import UTC, datetime
from decimal import Decimal

from app.alpaca.gateway import AlpacaGateway
from app.workflows.state import (
    OptionLeg,
    OrderRequest,
    OrderResult,
    OrderStatus,
    StrategyCandidate,
    SubmissionChannel,
)

_STATUS_MAP = {
    "new": OrderStatus.NEW,
    "accepted": OrderStatus.ACCEPTED,
    "pending_new": OrderStatus.NEW,
    "partially_filled": OrderStatus.PARTIALLY_FILLED,
    "filled": OrderStatus.FILLED,
    "rejected": OrderStatus.REJECTED,
    "canceled": OrderStatus.CANCELLED,
    "cancelled": OrderStatus.CANCELLED,
    "expired": OrderStatus.EXPIRED,
}


def build_idempotency_key(proposal_id: str) -> str:
    """Deterministic from the proposal id alone -- calling this twice for the
    same proposal always yields the same key, which is what makes the
    pre-submit `get_order_by_client_id` lookup an effective duplicate guard."""
    return hashlib.sha256(f"aura-proposal-{proposal_id}".encode()).hexdigest()[:32]


def build_order_request(candidate: StrategyCandidate, proposal_id: str, qty: int = 1) -> OrderRequest:
    idempotency_key = build_idempotency_key(proposal_id)
    limit_price = _net_price(candidate.legs, candidate.net_debit, candidate.net_credit)
    return OrderRequest(
        idempotency_key=idempotency_key,
        client_order_id=idempotency_key,
        order_class="mleg",
        qty=qty,
        time_in_force="day",
        limit_price=limit_price,
        legs=candidate.legs,
    )


def _net_price(legs: list[OptionLeg], net_debit: Decimal | None, net_credit: Decimal | None) -> Decimal:
    if net_debit is not None:
        return net_debit
    if net_credit is not None:
        return net_credit
    return sum((leg.quote_mid for leg in legs), Decimal(0))


async def execute(order_request: OrderRequest, gateway: AlpacaGateway) -> OrderResult:
    existing = await gateway.get_order_by_client_id(order_request.client_order_id)
    if existing is not None:
        return OrderResult(
            broker_order_id=existing.broker_order_id,
            client_order_id=existing.client_order_id,
            status=_STATUS_MAP.get(existing.status, OrderStatus.UNKNOWN_RECONCILING),
            submitted_via=SubmissionChannel(gateway.channel_name),
            submitted_at=datetime.now(UTC),
            raw_response_sanitized=existing.raw_response_sanitized,
            reconciliation_note="Idempotent replay: an order already exists for this client_order_id; no new order was submitted.",
        )

    legs_payload = [
        {
            "occ_symbol": leg.occ_symbol,
            "ratio_qty": leg.ratio_qty,
            "side": leg.side.value,
            "position_intent": leg.position_intent.value,
        }
        for leg in order_request.legs
    ]
    try:
        submitted = await gateway.submit_option_order(
            legs=legs_payload,
            qty=order_request.qty,
            limit_price=order_request.limit_price,
            client_order_id=order_request.client_order_id,
            time_in_force=order_request.time_in_force,
        )
    except Exception as exc:  # noqa: BLE001 -- broker call, must not crash the graph run
        return OrderResult(
            broker_order_id=None,
            client_order_id=order_request.client_order_id,
            status=OrderStatus.UNKNOWN_RECONCILING,
            submitted_via=SubmissionChannel(gateway.channel_name),
            submitted_at=datetime.now(UTC),
            raw_response_sanitized={},
            reconciliation_note=(
                f"Submission raised {type(exc).__name__}; outcome is uncertain. "
                "Reconcile via get_order_by_client_id before retrying -- never blind-retry a submit."
            ),
        )

    return OrderResult(
        broker_order_id=submitted.broker_order_id,
        client_order_id=submitted.client_order_id,
        status=_STATUS_MAP.get(submitted.status, OrderStatus.UNKNOWN_RECONCILING),
        submitted_via=SubmissionChannel(gateway.channel_name),
        submitted_at=datetime.now(UTC),
        raw_response_sanitized=submitted.raw_response_sanitized,
    )
