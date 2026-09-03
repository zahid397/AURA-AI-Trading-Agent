from datetime import UTC, date, datetime
from decimal import Decimal
from uuid import uuid4

import pytest

from app.agents import execution
from app.alpaca.demo_adapter import DemoAlpacaAdapter
from app.workflows.state import (
    OptionLeg,
    OptionType,
    OrderSide,
    PositionIntent,
    StrategyCandidate,
)


def _candidate() -> StrategyCandidate:
    now = datetime.now(UTC)
    leg = OptionLeg(
        occ_symbol="SPY260101C00100000", underlying="SPY", option_type=OptionType.CALL,
        strike=Decimal(100), expiration=date(2026, 1, 1), side=OrderSide.BUY,
        position_intent=PositionIntent.BUY_TO_OPEN, ratio_qty=1,
        quote_bid=Decimal("1.00"), quote_ask=Decimal("1.10"), quote_mid=Decimal("1.05"), quote_time=now,
    )
    from app.workflows.state import Direction, StrategyType

    return StrategyCandidate(
        candidate_id=uuid4(), symbol="SPY", strategy_type=StrategyType.CASH_SECURED_PUT, regime=Direction.BULLISH,
        legs=[leg], max_profit=Decimal(100), max_loss=Decimal(400), breakevens=[Decimal(99)],
        return_on_risk=Decimal("0.25"), probability_of_profit=Decimal("0.6"), liquidity_score=Decimal("0.8"),
        expected_return=Decimal(60), dte=30, quote_timestamp=now, score=Decimal("0.7"), thesis="test",
    )


@pytest.mark.asyncio
async def test_first_submission_is_not_reported_as_a_replay():
    gateway = DemoAlpacaAdapter()
    candidate = _candidate()
    order_request = execution.build_order_request(candidate, proposal_id="run-1")
    result = await execution.execute(order_request, gateway)
    assert result.reconciliation_note is None
    assert result.status.value == "filled"


@pytest.mark.asyncio
async def test_second_submission_with_the_same_proposal_is_an_idempotent_replay():
    gateway = DemoAlpacaAdapter()
    candidate = _candidate()
    order_request = execution.build_order_request(candidate, proposal_id="run-2")
    first = await execution.execute(order_request, gateway)
    second = await execution.execute(order_request, gateway)
    assert first.broker_order_id == second.broker_order_id
    assert second.reconciliation_note is not None and "idempotent" in second.reconciliation_note.lower()


def test_idempotency_key_is_deterministic_per_proposal():
    a = execution.build_idempotency_key("run-42")
    b = execution.build_idempotency_key("run-42")
    c = execution.build_idempotency_key("run-43")
    assert a == b
    assert a != c
