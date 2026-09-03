"""Strict Pydantic state for the AURA LangGraph trading workflow.

This module is the single source of truth for what flows through the graph
defined in `trading_graph.py`. Every node reads a subset of `GraphState` and
returns a partial update; nothing outside these models crosses a node
boundary. Money and price fields are `Decimal`, never `float` -- see
docs/architecture.md for why.
"""
from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal
from enum import Enum
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", validate_assignment=True)


# --------------------------------------------------------------------------
# Run identity, mode, trigger
# --------------------------------------------------------------------------

class RunMode(str, Enum):
    DEMO = "demo"
    OBSERVE = "observe"
    APPROVAL_REQUIRED = "approval_required"
    PAPER_AUTONOMOUS = "paper_autonomous"


class RunTrigger(str, Enum):
    MANUAL = "manual"
    SCHEDULED = "scheduled"
    APPROVAL_RESUME = "approval_resume"


class RunStatus(str, Enum):
    PENDING = "pending"
    RUNNING = "running"
    AWAITING_APPROVAL = "awaiting_approval"
    COMPLETED = "completed"
    FAILED = "failed"
    CANCELLED = "cancelled"
    REJECTED = "rejected"


class Direction(str, Enum):
    BULLISH = "bullish"
    BEARISH = "bearish"
    NEUTRAL = "neutral"


class StrategyType(str, Enum):
    BULL_CALL_SPREAD = "bull_call_spread"
    BEAR_PUT_SPREAD = "bear_put_spread"
    IRON_CONDOR = "iron_condor"
    COVERED_CALL = "covered_call"
    CASH_SECURED_PUT = "cash_secured_put"


class OptionType(str, Enum):
    CALL = "call"
    PUT = "put"


class OrderSide(str, Enum):
    BUY = "buy"
    SELL = "sell"


class PositionIntent(str, Enum):
    BUY_TO_OPEN = "buy_to_open"
    BUY_TO_CLOSE = "buy_to_close"
    SELL_TO_OPEN = "sell_to_open"
    SELL_TO_CLOSE = "sell_to_close"


class RiskSeverity(str, Enum):
    INFO = "info"
    WARNING = "warning"
    CRITICAL = "critical"


class RiskResult(str, Enum):
    APPROVED = "approved"
    APPROVAL_REQUIRED = "approval_required"
    REJECTED = "rejected"


class SubmissionChannel(str, Enum):
    ALPACA_API = "alpaca_api"
    ALPACA_MCP = "alpaca_mcp"
    ALPACA_CLI = "alpaca_cli"
    DEMO = "demo"


class OrderStatus(str, Enum):
    NEW = "new"
    ACCEPTED = "accepted"
    PARTIALLY_FILLED = "partially_filled"
    FILLED = "filled"
    REJECTED = "rejected"
    CANCELLED = "cancelled"
    EXPIRED = "expired"
    UNKNOWN_RECONCILING = "unknown_reconciling"


class RunMeta(StrictModel):
    run_id: UUID
    trace_id: UUID
    user_id: UUID
    mode: RunMode
    trigger: RunTrigger
    risk_policy_version: int
    alpaca_connection_id: UUID | None = None
    created_at: datetime
    updated_at: datetime
    status: RunStatus = RunStatus.PENDING


# --------------------------------------------------------------------------
# Universe / capabilities / market data
# --------------------------------------------------------------------------

class ScanFilters(StrictModel):
    direction: Direction | None = None
    strategies: list[StrategyType] = Field(default_factory=list)
    min_dte: int = 7
    max_dte: int = 45
    min_confidence: Decimal = Decimal("0.55")
    max_risk_pct_of_equity: Decimal = Decimal("0.02")
    min_open_interest: int = 100
    min_volume: int = 10
    max_spread_pct: Decimal = Decimal("0.15")


class SymbolUniverse(StrictModel):
    symbols: list[str]
    filters: ScanFilters


class AlpacaCapabilitySnapshot(StrictModel):
    trading_api_connected: bool
    market_data_connected: bool
    options_level: int | None = None
    mcp_connected: bool
    mcp_tool_count: int = 0
    cli_available: bool
    cli_version: str | None = None
    paper_verified: bool
    checked_at: datetime


class Bar(StrictModel):
    t: datetime
    open: Decimal
    high: Decimal
    low: Decimal
    close: Decimal
    volume: int


class MarketDataSnapshot(StrictModel):
    symbol: str
    last_price: Decimal
    bars: list[Bar] = Field(default_factory=list)
    quote_bid: Decimal | None = None
    quote_ask: Decimal | None = None
    quote_time: datetime
    is_stale: bool = False
    data_source: str  # "alpaca_api" | "alpaca_mcp" | "demo_fixture"


# --------------------------------------------------------------------------
# Deterministic features
# --------------------------------------------------------------------------

class TechnicalFeatures(StrictModel):
    symbol: str
    computed_at: datetime
    ema_fast: Decimal
    ema_slow: Decimal
    ema_trend: Direction
    rsi_14: Decimal
    macd: Decimal
    macd_signal: Decimal
    atr_14: Decimal
    realized_vol_annualized: Decimal
    iv_percentile: Decimal | None = None
    relative_volume: Decimal
    open_interest: int | None = None
    bid_ask_spread_pct: Decimal | None = None
    days_to_next_event: int | None = None
    warnings: list[str] = Field(default_factory=list)


# --------------------------------------------------------------------------
# Market thesis (Market Analyst Agent)
# --------------------------------------------------------------------------

class EvidenceItem(StrictModel):
    label: str
    value: str
    observed_at: datetime
    source: str


class MarketThesis(StrictModel):
    symbol: str
    direction: Direction
    confidence: Decimal = Field(ge=0, le=1)
    thesis: str
    evidence: list[EvidenceItem]
    invalidation_conditions: list[str]
    data_quality_warnings: list[str] = Field(default_factory=list)
    generated_at: datetime


# --------------------------------------------------------------------------
# Options Strategy Agent
# --------------------------------------------------------------------------

class OptionLeg(StrictModel):
    occ_symbol: str
    underlying: str
    option_type: OptionType
    strike: Decimal
    expiration: date
    side: OrderSide
    position_intent: PositionIntent
    ratio_qty: int = Field(ge=1)
    quote_bid: Decimal
    quote_ask: Decimal
    quote_mid: Decimal
    delta: Decimal | None = None
    gamma: Decimal | None = None
    theta: Decimal | None = None
    vega: Decimal | None = None
    open_interest: int | None = None
    volume: int | None = None
    quote_time: datetime


class PayoffPoint(StrictModel):
    underlying_price: Decimal
    pnl: Decimal


class StrategyCandidate(StrictModel):
    candidate_id: UUID
    symbol: str
    strategy_type: StrategyType
    regime: Direction
    legs: list[OptionLeg]
    contract_multiplier: int = 100
    net_debit: Decimal | None = None
    net_credit: Decimal | None = None
    max_profit: Decimal
    max_loss: Decimal
    breakevens: list[Decimal]
    return_on_risk: Decimal
    probability_of_profit: Decimal
    liquidity_score: Decimal
    expected_return: Decimal
    dte: int
    quote_timestamp: datetime
    payoff_curve: list[PayoffPoint] = Field(default_factory=list)
    score: Decimal
    score_components: dict[str, Decimal] = Field(default_factory=dict)
    score_weights: dict[str, Decimal] = Field(default_factory=dict)
    thesis: str
    rank: int | None = None


# --------------------------------------------------------------------------
# Risk Guardian Agent + deterministic risk engine
# --------------------------------------------------------------------------

class RiskRuleResult(StrictModel):
    rule_key: str
    description: str
    observed_value: str
    limit_value: str
    passed: bool
    severity: RiskSeverity
    explanation: str


class RiskEvaluation(StrictModel):
    evaluation_id: UUID
    risk_policy_version: int
    result: RiskResult
    risk_score: Decimal
    rule_results: list[RiskRuleResult]
    evaluated_at: datetime


class ApprovalState(StrictModel):
    required: bool
    status: str = "pending"  # pending | approved | rejected | expired
    approved_by: UUID | None = None
    decided_at: datetime | None = None
    notes: str | None = None
    expires_at: datetime | None = None


# --------------------------------------------------------------------------
# Execution Agent
# --------------------------------------------------------------------------

class OrderRequest(StrictModel):
    idempotency_key: str
    client_order_id: str
    order_class: str = "mleg"
    qty: int
    time_in_force: str = "day"
    limit_price: Decimal
    legs: list[OptionLeg]


class OrderResult(StrictModel):
    broker_order_id: str | None = None
    client_order_id: str
    status: OrderStatus
    submitted_via: SubmissionChannel
    submitted_at: datetime
    raw_response_sanitized: dict = Field(default_factory=dict)
    reconciliation_note: str | None = None


# --------------------------------------------------------------------------
# Monitoring + Learning
# --------------------------------------------------------------------------

class MonitoringState(StrictModel):
    status: str = "monitoring"  # monitoring | exit_triggered | closed
    exit_conditions: list[str] = Field(default_factory=list)
    last_checked_at: datetime | None = None


class PerformanceOutcome(StrictModel):
    realized_pl: Decimal | None = None
    win: bool | None = None
    time_in_trade_hours: Decimal | None = None
    slippage_vs_mid: Decimal | None = None
    thesis_valid: bool | None = None


class LearningRecommendation(StrictModel):
    recommendation_id: UUID
    based_on_sample_size: int
    proposed_changes: dict[str, str]
    expected_effect: str
    requires_review: bool = True
    rollback_of: UUID | None = None


# --------------------------------------------------------------------------
# Errors / audit
# --------------------------------------------------------------------------

class RunError(StrictModel):
    node: str
    message: str
    retryable: bool
    occurred_at: datetime


class AuditEvent(StrictModel):
    event_id: UUID
    type: str
    payload: dict
    occurred_at: datetime


# --------------------------------------------------------------------------
# Composite graph state
# --------------------------------------------------------------------------

class GraphState(StrictModel):
    meta: RunMeta
    universe: SymbolUniverse
    capabilities: AlpacaCapabilitySnapshot | None = None
    market_data: dict[str, MarketDataSnapshot] = Field(default_factory=dict)
    features: dict[str, TechnicalFeatures] = Field(default_factory=dict)
    theses: dict[str, MarketThesis] = Field(default_factory=dict)
    candidates: list[StrategyCandidate] = Field(default_factory=list)
    selected_strategy: StrategyCandidate | None = None
    risk_evaluation: RiskEvaluation | None = None
    approval: ApprovalState | None = None
    order_request: OrderRequest | None = None
    order_result: OrderResult | None = None
    monitoring: MonitoringState | None = None
    performance: PerformanceOutcome | None = None
    learning: LearningRecommendation | None = None
    errors: list[RunError] = Field(default_factory=list)
    retry_counts: dict[str, int] = Field(default_factory=dict)
    audit_events: list[AuditEvent] = Field(default_factory=list)
    rejection_reason: str | None = None
