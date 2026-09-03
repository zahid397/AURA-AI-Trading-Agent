"""Deterministic risk engine -- the hard gate an LLM cannot edit or bypass.

`evaluate` takes plain, already-computed inputs (never calls an LLM, never
reads a prompt) and returns a `RiskEvaluation` with one `RiskRuleResult` per
rule, in a fixed, always-fully-populated order so the UI can render the same
rule list for both approvals and rejections. Any CRITICAL failure forces
`REJECTED`; with zero CRITICAL failures and zero WARNINGs the result is
`APPROVED`; a WARNING-only outcome maps to `APPROVAL_REQUIRED` unless the run
mode is `paper_autonomous`, in which case the caller (the graph node) decides
whether warnings still block autonomous execution -- this function only
reports facts, it does not know the run's mode.
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime
from decimal import Decimal
from uuid import uuid4

from app.risk.policy import RiskPolicyLimits
from app.workflows.state import (
    AlpacaCapabilitySnapshot,
    RiskEvaluation,
    RiskResult,
    RiskRuleResult,
    RiskSeverity,
    StrategyCandidate,
)


@dataclass
class AccountRiskContext:
    equity: Decimal
    buying_power: Decimal
    daily_realized_pl: Decimal
    daily_unrealized_pl: Decimal
    peak_equity_30d: Decimal
    open_positions_count: int
    open_options_exposure: Decimal
    open_exposure_by_symbol: dict[str, Decimal]
    kill_switch_engaged: bool
    market_is_open: bool


def _rule(key: str, description: str, observed, limit, passed: bool, severity: RiskSeverity, explanation: str) -> RiskRuleResult:
    return RiskRuleResult(
        rule_key=key,
        description=description,
        observed_value=str(observed),
        limit_value=str(limit),
        passed=passed,
        severity=severity,
        explanation=explanation,
    )


def evaluate(
    candidate: StrategyCandidate,
    account: AccountRiskContext,
    capabilities: AlpacaCapabilitySnapshot,
    policy: RiskPolicyLimits,
    policy_version: int,
    confidence: Decimal,
    quote_age_seconds: int,
    duplicate_pending_proposal: bool,
) -> RiskEvaluation:
    results: list[RiskRuleResult] = []

    results.append(_rule(
        "paper_environment", "Trading environment must be verified paper, never live.",
        "paper" if capabilities.paper_verified else "unverified", "paper",
        capabilities.paper_verified,
        RiskSeverity.CRITICAL,
        "Hard guard satisfied." if capabilities.paper_verified else "Paper account status could not be verified -- refusing to trade.",
    ))
    results.append(_rule(
        "trading_api_connected", "Alpaca trading API must be reachable.",
        capabilities.trading_api_connected, True, capabilities.trading_api_connected,
        RiskSeverity.CRITICAL,
        "Connected." if capabilities.trading_api_connected else "Trading API unreachable -- cannot verify account or submit orders.",
    ))
    required_level = 3 if candidate.strategy_type.value == "iron_condor" or len(candidate.legs) > 2 else 2
    options_ok = (capabilities.options_level or 0) >= required_level
    results.append(_rule(
        "options_capability", f"Account options level must be >= {required_level} for this strategy.",
        capabilities.options_level, required_level, options_ok,
        RiskSeverity.CRITICAL,
        "Sufficient options approval level." if options_ok else "Account is not approved for multi-leg strategies of this complexity.",
    ))
    results.append(_rule(
        "market_session", "Market must be open for a new options order.",
        account.market_is_open, True, account.market_is_open,
        RiskSeverity.CRITICAL,
        "Market is open." if account.market_is_open else "Market is closed; new entries are blocked until the next session.",
    ))
    quote_fresh = quote_age_seconds <= policy.max_quote_age_seconds
    results.append(_rule(
        "quote_freshness", "Quotes must be fresher than the configured max age.",
        f"{quote_age_seconds}s", f"{policy.max_quote_age_seconds}s", quote_fresh,
        RiskSeverity.CRITICAL,
        "Quotes are fresh." if quote_fresh else "Quotes are stale; re-scan before proposing this trade again.",
    ))
    conf_ok = confidence >= policy.min_confidence
    results.append(_rule(
        "min_confidence", "Thesis confidence must meet the policy minimum.",
        confidence, policy.min_confidence, conf_ok,
        RiskSeverity.CRITICAL,
        "Confidence meets the bar." if conf_ok else "Confidence is below the minimum required to risk capital.",
    ))

    min_oi = min((leg.open_interest or 0) for leg in candidate.legs) if candidate.legs else 0
    oi_ok = min_oi >= policy.min_open_interest
    results.append(_rule(
        "min_open_interest", "Every leg must clear the minimum open-interest floor.",
        min_oi, policy.min_open_interest, oi_ok,
        RiskSeverity.WARNING,
        "Liquidity looks adequate." if oi_ok else "Thin open interest raises slippage and exit risk.",
    ))
    max_spread_pct = max(
        ((leg.quote_ask - leg.quote_bid) / leg.quote_mid if leg.quote_mid else Decimal(0)) for leg in candidate.legs
    ) if candidate.legs else Decimal(0)
    spread_ok = max_spread_pct <= policy.max_spread_pct
    results.append(_rule(
        "max_spread_pct", "Bid/ask spread must stay under the policy ceiling.",
        f"{max_spread_pct:.2%}", f"{policy.max_spread_pct:.2%}", spread_ok,
        RiskSeverity.WARNING,
        "Spreads are tight enough to trade." if spread_ok else "Wide spreads mean the real fill will likely be worse than mid.",
    ))

    max_loss_bounded = candidate.max_loss.is_finite() and candidate.max_loss >= 0
    results.append(_rule(
        "max_loss_bounded", "Max loss must be a calculable, finite, non-negative amount.",
        candidate.max_loss, "finite >= 0", max_loss_bounded,
        RiskSeverity.CRITICAL,
        "Max loss is well-defined." if max_loss_bounded else "This strategy has an undefined or unbounded loss -- refusing to trade it.",
    ))

    per_trade_cap = account.equity * policy.max_loss_per_trade_pct_equity
    per_trade_ok = candidate.max_loss <= per_trade_cap
    results.append(_rule(
        "max_loss_per_trade", "Max loss must stay within the per-trade % of equity cap.",
        candidate.max_loss, per_trade_cap.quantize(Decimal("0.01")), per_trade_ok,
        RiskSeverity.CRITICAL,
        "Within the per-trade risk budget." if per_trade_ok else "This trade alone would exceed the per-trade loss cap.",
    ))

    position_cap = account.equity * policy.max_position_risk_pct_equity
    position_ok = candidate.max_loss <= position_cap and candidate.max_loss <= account.buying_power
    results.append(_rule(
        "position_size_buying_power", "Position risk must fit within buying power and the position-risk cap.",
        candidate.max_loss, min(position_cap, account.buying_power).quantize(Decimal("0.01")), position_ok,
        RiskSeverity.CRITICAL,
        "Fits comfortably." if position_ok else "Not enough buying power or exceeds the single-position risk cap.",
    ))

    projected_total_exposure = account.open_options_exposure + candidate.max_loss
    total_cap = account.equity * policy.max_total_options_exposure_pct_equity
    total_ok = projected_total_exposure <= total_cap
    results.append(_rule(
        "max_total_exposure", "Total options exposure across the book must stay under the portfolio cap.",
        projected_total_exposure.quantize(Decimal("0.01")), total_cap.quantize(Decimal("0.01")), total_ok,
        RiskSeverity.CRITICAL,
        "Total exposure stays within policy." if total_ok else "Adding this trade would push total options exposure over the portfolio cap.",
    ))

    existing_symbol_exposure = account.open_exposure_by_symbol.get(candidate.symbol, Decimal(0))
    projected_symbol_exposure = existing_symbol_exposure + candidate.max_loss
    symbol_cap = account.equity * policy.max_symbol_exposure_pct_equity
    symbol_ok = projected_symbol_exposure <= symbol_cap
    results.append(_rule(
        "max_symbol_exposure", f"Exposure to {candidate.symbol} must stay under the per-symbol cap.",
        projected_symbol_exposure.quantize(Decimal("0.01")), symbol_cap.quantize(Decimal("0.01")), symbol_ok,
        RiskSeverity.CRITICAL,
        "Within the per-symbol concentration limit." if symbol_ok else f"This trade would concentrate too much risk in {candidate.symbol}.",
    ))

    positions_ok = account.open_positions_count < policy.max_open_positions
    results.append(_rule(
        "max_open_positions", "Number of open positions must stay under the policy limit.",
        account.open_positions_count, policy.max_open_positions, positions_ok,
        RiskSeverity.CRITICAL,
        "Room for another position." if positions_ok else "Already at the maximum number of concurrent open positions.",
    ))

    daily_pl = account.daily_realized_pl + account.daily_unrealized_pl
    daily_loss_floor = -(account.equity * policy.max_daily_loss_pct_equity)
    daily_ok = daily_pl >= daily_loss_floor
    results.append(_rule(
        "max_daily_loss", "Today's realized + unrealized P&L must not have breached the daily loss limit.",
        daily_pl.quantize(Decimal("0.01")), daily_loss_floor.quantize(Decimal("0.01")), daily_ok,
        RiskSeverity.CRITICAL,
        "Daily loss limit not breached." if daily_ok else "Daily loss limit already breached; no new risk permitted today.",
    ))

    drawdown_pct = (
        (account.peak_equity_30d - account.equity) / account.peak_equity_30d
        if account.peak_equity_30d > 0 else Decimal(0)
    )
    drawdown_ok = drawdown_pct <= policy.max_drawdown_pct_equity
    results.append(_rule(
        "max_drawdown", "Rolling drawdown from the 30-day peak must stay under the policy limit.",
        f"{drawdown_pct:.2%}", f"{policy.max_drawdown_pct_equity:.2%}", drawdown_ok,
        RiskSeverity.CRITICAL,
        "Drawdown within tolerance." if drawdown_ok else "Rolling drawdown limit breached; new risk is blocked until it recovers.",
    ))

    dte_ok = policy.min_dte <= candidate.dte <= policy.max_dte
    results.append(_rule(
        "expiration_window", "Days-to-expiration must fall inside the configured window.",
        candidate.dte, f"{policy.min_dte}-{policy.max_dte}", dte_ok,
        RiskSeverity.WARNING,
        "DTE is in range." if dte_ok else "DTE is outside the configured window (assignment/pin risk or too little time value).",
    ))

    results.append(_rule(
        "kill_switch", "Global kill switch must not be engaged.",
        account.kill_switch_engaged, False, not account.kill_switch_engaged,
        RiskSeverity.CRITICAL,
        "Kill switch is off." if not account.kill_switch_engaged else "Global kill switch is engaged; no new orders permitted.",
    ))

    results.append(_rule(
        "duplicate_proposal", "There must be no other pending proposal for this exact candidate.",
        duplicate_pending_proposal, False, not duplicate_pending_proposal,
        RiskSeverity.CRITICAL,
        "No duplicate in flight." if not duplicate_pending_proposal else "An identical proposal is already pending -- refusing to create a second one.",
    ))

    has_critical_failure = any((not r.passed) and r.severity == RiskSeverity.CRITICAL for r in results)
    has_warning_failure = any((not r.passed) and r.severity == RiskSeverity.WARNING for r in results)

    if has_critical_failure:
        result = RiskResult.REJECTED
    elif has_warning_failure:
        result = RiskResult.APPROVAL_REQUIRED
    else:
        result = RiskResult.APPROVED

    passed_count = sum(1 for r in results if r.passed)
    risk_score = (Decimal(passed_count) / Decimal(len(results))) * Decimal(100) if results else Decimal(0)

    return RiskEvaluation(
        evaluation_id=uuid4(),
        risk_policy_version=policy_version,
        result=result,
        risk_score=risk_score.quantize(Decimal("0.1")),
        rule_results=results,
        evaluated_at=datetime.now(UTC),
    )
