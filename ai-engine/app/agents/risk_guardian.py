"""Risk Guardian Agent.

The LLM may narrate *why* the deterministic result came out the way it did;
it never sees a code path that could change `RiskResult`, add/remove a rule,
or alter a limit. `app/risk/engine.evaluate` is the actual gate -- this
module is a thin wrapper that calls it and then asks the LLM to summarize
the (already-final) rule_results in one sentence for the UI.
"""
from __future__ import annotations

from decimal import Decimal

from app.llm.provider import LLMProvider
from app.risk import engine
from app.risk.engine import AccountRiskContext
from app.risk.policy import RiskPolicyLimits
from app.workflows.state import (
    AlpacaCapabilitySnapshot,
    RiskEvaluation,
    StrategyCandidate,
)


async def review(
    candidate: StrategyCandidate,
    account: AccountRiskContext,
    capabilities: AlpacaCapabilitySnapshot,
    policy: RiskPolicyLimits,
    policy_version: int,
    confidence: Decimal,
    quote_age_seconds: int,
    duplicate_pending_proposal: bool,
    llm: LLMProvider,
) -> tuple[RiskEvaluation, str]:
    evaluation = engine.evaluate(
        candidate=candidate,
        account=account,
        capabilities=capabilities,
        policy=policy,
        policy_version=policy_version,
        confidence=confidence,
        quote_age_seconds=quote_age_seconds,
        duplicate_pending_proposal=duplicate_pending_proposal,
    )

    failed = [r for r in evaluation.rule_results if not r.passed]
    rule_lines = "\n".join(f"- [{r.severity.value}] {r.rule_key}: {r.explanation}" for r in failed) or "- none (all rules passed)"
    prompt = (
        f"Result: {evaluation.result.value}\nRisk score: {evaluation.risk_score}/100\n"
        f"Failed/warning rules:\n{rule_lines}\n\n"
        "Write one sentence summarizing this outcome for a trader reviewing it. "
        "Do not suggest overriding or bypassing any rule."
    )
    summary = await llm.generate(
        system="You summarize a deterministic risk verdict. You cannot change the verdict or any rule.",
        user=prompt,
    )
    return evaluation, summary
