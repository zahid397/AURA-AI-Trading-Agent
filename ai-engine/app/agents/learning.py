"""Learning Agent.

Analyzes a batch of already-closed trade outcomes (supplied by the caller --
Laravel is the system of record for trade history, so this agent never reads
a database directly) and proposes *versioned, review-required* changes. It
can never mutate `RiskPolicyLimits`, prompts, or strategy code directly --
`LearningRecommendation.requires_review` is always `True` and the caller
(Laravel) is responsible for the human-reviewed activation step.
"""
from __future__ import annotations

from decimal import Decimal
from uuid import uuid4

from app.workflows.state import LearningRecommendation, PerformanceOutcome

MIN_SAMPLE_SIZE_FOR_RECOMMENDATION = 10


def analyze(recent_outcomes: list[PerformanceOutcome]) -> LearningRecommendation | None:
    sample_size = len(recent_outcomes)
    if sample_size < MIN_SAMPLE_SIZE_FOR_RECOMMENDATION:
        return None  # not enough data to recommend anything responsibly

    decided = [o for o in recent_outcomes if o.win is not None]
    if not decided:
        return None
    win_rate = sum(1 for o in decided if o.win) / len(decided)

    slippage_samples = [o.slippage_vs_mid for o in recent_outcomes if o.slippage_vs_mid is not None]
    avg_slippage = (sum(slippage_samples, Decimal(0)) / len(slippage_samples)) if slippage_samples else None

    changes: dict[str, str] = {}
    if win_rate < 0.40:
        changes["min_confidence"] = "increase by 0.05 -- recent win rate is below 40% over a meaningful sample"
    if avg_slippage is not None and avg_slippage > Decimal("0.05"):
        changes["max_spread_pct"] = "tighten by 0.02 -- average fill slippage vs. mid has been elevated"

    if not changes:
        return None

    return LearningRecommendation(
        recommendation_id=uuid4(),
        based_on_sample_size=sample_size,
        proposed_changes=changes,
        expected_effect=(
            f"Observed win rate {win_rate:.0%} over {len(decided)} decided trades"
            + (f", average slippage vs. mid {avg_slippage:.2%}" if avg_slippage is not None else "")
            + ". Proposed changes aim to raise trade quality at the cost of fewer signals passing the gate."
        ),
        requires_review=True,
    )
