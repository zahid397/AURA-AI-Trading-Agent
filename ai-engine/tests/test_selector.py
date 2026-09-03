from datetime import UTC, datetime
from decimal import Decimal

import pytest

from app.alpaca.demo_adapter import DemoAlpacaAdapter
from app.strategies.selector import (
    build_candidate,
    classify_vol_regime,
    rank_candidates,
    select_strategy_type,
)
from app.workflows.state import (
    Direction,
    EvidenceItem,
    MarketThesis,
    StrategyType,
    TechnicalFeatures,
)


def _thesis(direction: Direction, confidence: str = "0.80") -> MarketThesis:
    return MarketThesis(
        symbol="SPY", direction=direction, confidence=Decimal(confidence),
        thesis="test thesis", evidence=[EvidenceItem(label="RSI", value="65", observed_at=datetime.now(UTC), source="demo")],
        invalidation_conditions=["price closes below 20-day SMA"], generated_at=datetime.now(UTC),
    )


def _features(vol: float) -> TechnicalFeatures:
    return TechnicalFeatures(
        symbol="SPY", computed_at=datetime.now(UTC),
        ema_fast=Decimal(101), ema_slow=Decimal(99), ema_trend=Direction.BULLISH,
        rsi_14=Decimal(65), macd=Decimal("0.5"), macd_signal=Decimal("0.3"), atr_14=Decimal("2.1"),
        realized_vol_annualized=Decimal(str(vol)), relative_volume=Decimal("1.2"),
    )


def test_vol_regime_classification():
    assert classify_vol_regime(0.10) == "low"
    assert classify_vol_regime(0.20) == "moderate"
    assert classify_vol_regime(0.40) == "high"


def test_strategy_selection_matches_policy_table():
    assert select_strategy_type(Direction.BULLISH, "moderate") == StrategyType.BULL_CALL_SPREAD
    assert select_strategy_type(Direction.BEARISH, "moderate") == StrategyType.BEAR_PUT_SPREAD
    assert select_strategy_type(Direction.NEUTRAL, "moderate") == StrategyType.IRON_CONDOR
    assert select_strategy_type(Direction.NEUTRAL, "low") == StrategyType.COVERED_CALL
    assert select_strategy_type(Direction.BULLISH, "high") == StrategyType.CASH_SECURED_PUT


@pytest.mark.asyncio
async def test_build_candidate_bull_call_spread_end_to_end():
    adapter = DemoAlpacaAdapter()
    bars = await adapter.get_bars("SPY")
    chain = await adapter.get_option_chain("SPY", min_dte=20, max_dte=40)
    from app.workflows.state import ScanFilters

    candidate = build_candidate(
        symbol="SPY", spot=bars.last_price, thesis=_thesis(Direction.BULLISH),
        features=_features(0.20), chain=chain, filters=ScanFilters(),
    )
    assert candidate is not None
    assert candidate.strategy_type == StrategyType.BULL_CALL_SPREAD
    assert len(candidate.legs) == 2
    assert candidate.max_loss > 0
    assert candidate.max_profit > 0
    assert Decimal(0) <= candidate.score <= Decimal(1)


@pytest.mark.asyncio
async def test_build_candidate_iron_condor_has_four_legs():
    adapter = DemoAlpacaAdapter()
    bars = await adapter.get_bars("SPY")
    chain = await adapter.get_option_chain("SPY", min_dte=20, max_dte=40)
    from app.workflows.state import ScanFilters

    candidate = build_candidate(
        symbol="SPY", spot=bars.last_price, thesis=_thesis(Direction.NEUTRAL),
        features=_features(0.20), chain=chain, filters=ScanFilters(),
    )
    assert candidate is not None
    assert candidate.strategy_type == StrategyType.IRON_CONDOR
    assert len(candidate.legs) == 4


def test_rank_candidates_orders_by_score_descending():
    import uuid

    from app.workflows.state import StrategyCandidate

    def fake(score: str) -> StrategyCandidate:
        return StrategyCandidate(
            candidate_id=uuid.uuid4(), symbol="SPY", strategy_type=StrategyType.BULL_CALL_SPREAD, regime=Direction.BULLISH,
            legs=[], max_profit=Decimal(1), max_loss=Decimal(1), breakevens=[Decimal(100)],
            return_on_risk=Decimal(1), probability_of_profit=Decimal("0.5"), liquidity_score=Decimal("0.5"),
            expected_return=Decimal(1), dte=30, quote_timestamp=datetime.now(UTC), score=Decimal(score), thesis="t",
        )

    ranked = rank_candidates([fake("0.5"), fake("0.9"), fake("0.2")])
    assert [str(c.score) for c in ranked] == ["0.9", "0.5", "0.2"]
    assert [c.rank for c in ranked] == [1, 2, 3]
