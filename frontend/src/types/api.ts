/**
 * Types mirroring the Laravel API contract in docs/api-routes.md and the
 * resource shapes in docs/db-schema.md.
 *
 * Money / price / percentage fields are typed as `string` because the
 * backend sends `decimal(18,4)` columns serialized as strings (Laravel's
 * default decimal cast to JSON). Never do float math on these beyond
 * formatting -- see src/utils/format.ts.
 */

// ---------------------------------------------------------------------------
// Envelope
// ---------------------------------------------------------------------------

export interface ApiMeta {
  request_id?: string
  [key: string]: unknown
}

export interface ApiSuccess<T> {
  data: T
  meta?: ApiMeta
}

export interface ApiErrorBody {
  code: string
  message: string
  request_id?: string
  fields?: Record<string, string[]>
}

export interface ApiErrorEnvelope {
  error: ApiErrorBody
}

export interface PaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  path: string
  per_page: number
  to: number | null
  total: number
}

export interface Paginated<T> {
  data: T[]
  links: PaginationLinks
  meta: PaginationMeta
}

// ---------------------------------------------------------------------------
// Enums / unions
// ---------------------------------------------------------------------------

export type AutonomyMode = 'demo' | 'observe' | 'approval_required' | 'paper_autonomous'
export type AgentType = 'market_analyst' | 'options_strategy' | 'risk_guardian' | 'execution' | 'learning'
export type AgentStatus = 'idle' | 'running' | 'paused' | 'error'
export type AgentRunTrigger = 'manual' | 'scheduled' | 'approval_resume'
export type AgentRunStatus =
  | 'pending'
  | 'running'
  | 'awaiting_approval'
  | 'completed'
  | 'failed'
  | 'cancelled'
  | 'rejected'
export type LogLevel = 'debug' | 'info' | 'warn' | 'error'

export type StrategyType =
  | 'bull_call_spread'
  | 'bear_put_spread'
  | 'iron_condor'
  | 'covered_call'
  | 'cash_secured_put'
export type Regime = 'bullish' | 'bearish' | 'neutral'
export type LegAction = 'buy' | 'sell'
export type OptionType = 'call' | 'put'
export type PositionIntent = 'buy_to_open' | 'sell_to_open' | 'buy_to_close' | 'sell_to_close'

export type RiskEvalResult = 'approved' | 'approval_required' | 'rejected'
export type RiskSeverity = 'info' | 'warning' | 'critical'
export type RiskLevel = 'LOW' | 'MODERATE' | 'ELEVATED' | 'HIGH'

export type ProposalStatus = 'pending' | 'approved' | 'rejected' | 'expired' | 'executed'

export type OrderStatus =
  | 'new'
  | 'accepted'
  | 'partially_filled'
  | 'filled'
  | 'rejected'
  | 'cancelled'
  | 'expired'
  | 'unknown_reconciling'
export type SubmittedVia = 'alpaca_api' | 'alpaca_mcp' | 'alpaca_cli' | 'demo'

export type PositionStatus = 'open' | 'closed'

export type BacktestStatus = 'pending' | 'running' | 'completed' | 'failed' | 'cancelled'

export type DataProvenance = 'historical' | 'simulated_fixture' | 'insufficient_data'

export type SystemComponentStatus = 'operational' | 'degraded' | 'down' | 'not_configured'

// ---------------------------------------------------------------------------
// Auth / user
// ---------------------------------------------------------------------------

export interface User {
  id: string
  name: string
  email: string
  timezone: string
  currency_display: string
  default_page: string
}

export interface UserSettings {
  id: string
  user_id: string
  mode: AutonomyMode
  confidence_threshold: string
  notification_prefs: {
    email_on_execution: boolean
    email_on_rejection: boolean
    email_on_kill_switch: boolean
    in_app: boolean
  }
}

export interface MeResponse {
  user: User
  settings: UserSettings
}

// ---------------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------------

export interface DashboardSummary {
  balance: string
  total_pl: string
  unrealized_pl: string
  realized_pl: string
  buying_power: string
  risk_level: RiskLevel
  starting_equity: string
  as_of: string
  latest_decision: LatestDecisionSummary | null
}

export interface LatestDecisionSummary {
  decision_id: string
  trade_proposal_id: string | null
  symbol: string
  strategy_type: StrategyType
  regime: Regime
  confidence: string
  thesis: string
  status: ProposalStatus | 'analyzed'
  created_at: string
}

export type EquityCurveRange = '1D' | '1W' | '1M' | '3M' | '1Y' | 'ALL'

export interface EquityCurvePoint {
  t: string
  equity: string
}

export interface ActivityEntry {
  id: string
  agent_type: AgentType
  message: string
  created_at: string
  level: LogLevel
  run_id?: string
}

// ---------------------------------------------------------------------------
// Agents
// ---------------------------------------------------------------------------

export interface Agent {
  id: string
  type: AgentType
  name: string
  enabled: boolean
  status: AgentStatus
  config_version: number
  current_task: string | null
  last_heartbeat_at: string | null
  decisions_today: number
  avg_latency_ms: number
}

export interface AgentPerformancePoint {
  t: string
  value: number
}

export interface AgentDetail extends Agent {
  objective: string
  current_run: AgentRunSummary | null
  performance_trend: AgentPerformancePoint[]
  recent_decisions: LatestDecisionSummary[]
}

export interface AgentRunSummary {
  id: string
  trigger: AgentRunTrigger
  mode: AutonomyMode
  status: AgentRunStatus
  started_at: string
  completed_at: string | null
  summary: string | null
  symbol_universe: string[]
}

export interface AgentRunNode {
  agent_type: AgentType
  status: AgentRunStatus | 'skipped'
  started_at: string | null
  completed_at: string | null
  summary: string | null
}

export interface AgentRunDetail extends AgentRunSummary {
  nodes: AgentRunNode[]
  trace_id: string
}

export interface AgentLogEntry {
  id: string
  agent_run_id: string
  agent_type: AgentType
  level: LogLevel
  message: string
  context: Record<string, unknown>
  created_at: string
}

// ---------------------------------------------------------------------------
// Strategies / scanner
// ---------------------------------------------------------------------------

export interface StrategyLeg {
  id: string
  strategy_id: string
  action: LegAction
  occ_symbol: string
  underlying: string
  option_type: OptionType
  strike: string
  expiration: string
  position_intent: PositionIntent
  ratio_qty: number
  quote_bid: string
  quote_ask: string
  delta: string | null
  gamma: string | null
  theta: string | null
  vega: string | null
  open_interest: number | null
  volume: number | null
  quote_time: string
}

export interface ScoreBreakdown {
  deterministic: { label: string; weight: number; value: number }[]
  model_judgment: { label: string; weight: number; value: number }[]
  total: number
}

export interface Strategy {
  id: string
  agent_run_id: string
  symbol: string
  strategy_type: StrategyType
  regime: Regime
  thesis: string
  confidence: string
  net_debit: string | null
  net_credit: string | null
  max_profit: string
  max_loss: string
  breakevens: string[]
  probability_of_profit: string
  liquidity_score: string
  score: string
  score_breakdown: ScoreBreakdown
  quote_timestamp: string
  dte: number
  rank: number | null
  selected: boolean
  legs?: StrategyLeg[]
}

export interface StrategyDetail extends Strategy {
  legs: StrategyLeg[]
  evidence: EvidenceSection[]
  audit: AuditRibbon
}

export interface EvidenceSection {
  key: 'market_conditions' | 'technical_signals' | 'volatility' | 'liquidity' | 'risk_analysis' | 'expected_outcome' | 'invalidation'
  title: string
  body: string
  bullets?: string[]
}

export interface AuditRibbon {
  run_id: string
  prompt_version: string
  config_version: number
  model_provider: string
  model_name: string
  tools_used: string[]
  risk_policy_version: number
  trace_url: string
}

export interface PayoffPoint {
  underlying_price: string
  pnl: string
}

export interface PayoffCurve {
  points: PayoffPoint[]
  breakevens: string[]
  max_profit: string
  max_loss: string
  current_price: string
}

export interface OptionScanFilters {
  direction?: 'bullish' | 'bearish' | 'neutral' | 'any'
  strategy?: StrategyType | 'any'
  dte_min?: number
  dte_max?: number
  iv_percentile_min?: number
  min_confidence?: number
  max_risk?: number
  min_open_interest?: number
  min_volume?: number
  max_spread_pct?: number
}

export interface OptionScan {
  id: string
  agent_run_id: string
  status: AgentRunStatus
  symbols: string[]
  filters: OptionScanFilters
  progress_pct: number
  created_at: string
  completed_at: string | null
  result_count: number
}

export interface SentimentSummary {
  symbol: string
  sentiment_score: number
  iv_percentile: number
  trend: Regime
}

// ---------------------------------------------------------------------------
// Risk
// ---------------------------------------------------------------------------

// Field names matter here beyond typing: PATCH /risk/policy validates against
// exactly these 10 keys under `limits` (UpdateRiskPolicyRequest::rules(), and
// DemoSeeder's seeded policy uses the same 10) -- an earlier version of this
// type used invented `_equity`-suffixed names for 5 of them and had 4 fields
// (max_loss_per_trade_pct_equity, min_volume, min_dte, max_dte) the backend
// has never had any concept of, so every policy save 422'd on 5 missing
// required fields.
export interface RiskPolicyLimits {
  max_daily_loss_pct: number
  max_drawdown_pct: number
  max_position_risk_pct: number
  max_total_exposure_pct: number
  max_symbol_exposure_pct: number
  min_confidence: number
  max_quote_age_seconds: number
  min_open_interest: number
  max_spread_pct: number
  max_open_positions: number
}

export interface RiskPolicy {
  id: string
  version: number
  name: string
  limits: RiskPolicyLimits
  active: boolean
  created_by: string | null
  created_at: string
}

export interface RiskOverview {
  risk_score: number
  risk_level: RiskLevel
  daily_pl: string
  daily_loss_limit: string
  exposure_pct: number
  max_exposure_pct: number
  position_sizing_budget: string
  drawdown_pct: number
  max_drawdown_pct: number
  remaining_capacity: string
  kill_switch_engaged: boolean
  exposure_by_underlying: { symbol: string; exposure: string; pct: number }[]
  exposure_by_strategy: { strategy_type: StrategyType; exposure: string; pct: number }[]
  drawdown_series: { t: string; drawdown_pct: number }[]
}

export interface RiskRuleResult {
  id: string
  rule_key: string
  description: string
  observed_value: string
  limit_value: string
  passed: boolean
  severity: RiskSeverity
  explanation: string
}

export interface RiskEvaluation {
  id: string
  trade_proposal_id: string
  risk_policy_id: string
  risk_policy_version: number
  result: RiskEvalResult
  risk_score: number
  evaluated_at: string
  rule_results: RiskRuleResult[]
}

export interface RiskAlert {
  id: string
  severity: RiskSeverity
  title: string
  message: string
  created_at: string
  acknowledged_at: string | null
  related_symbol?: string
}

// ---------------------------------------------------------------------------
// Portfolio
// ---------------------------------------------------------------------------

export interface PortfolioSummary {
  equity: string
  cash: string
  buying_power: string
  unrealized_pl: string
  realized_pl: string
  options_exposure: string
  starting_equity: string
  snapshot_at: string
}

export interface Position {
  id: string
  user_id: string
  strategy_id: string | null
  symbol: string
  strategy_type: StrategyType | null
  legs: StrategyLeg[]
  quantity: number
  avg_entry_price: string
  current_value: string
  unrealized_pl: string
  status: PositionStatus
  dte: number | null
  greeks: { delta: string; gamma: string; theta: string; vega: string } | null
  opened_at: string
  closed_at: string | null
}

export interface AllocationSlice {
  key: string
  label: string
  value: string
  pct: number
}

export interface PortfolioAllocation {
  by_underlying: AllocationSlice[]
  by_strategy: AllocationSlice[]
}

export interface PerformanceReport {
  id: string
  period_start: string
  period_end: string
  total_return: string
  win_rate: string
  profit_factor: string
  max_drawdown: string
  sharpe_like: string | null
  generated_at: string
  monthly_returns: { month: string; return_pct: number }[]
  avg_win: string
  avg_loss: string
  trade_count: number
}

// ---------------------------------------------------------------------------
// Proposals, execution, orders
// ---------------------------------------------------------------------------

export interface TradeProposal {
  id: string
  agent_run_id: string
  strategy_id: string
  user_id: string
  status: ProposalStatus
  risk_evaluation_id: string | null
  expires_at: string
  approved_at: string | null
  approved_by: string | null
  rejected_reason: string | null
  created_at: string
  strategy: Strategy
  risk_evaluation: RiskEvaluation | null
}

export interface OrderLeg {
  id: string
  order_id: string
  occ_symbol: string
  side: LegAction
  ratio_qty: number
  position_intent: PositionIntent
  filled_qty: number
  filled_avg_price: string | null
}

export interface Order {
  id: string
  trade_proposal_id: string
  broker_order_id: string | null
  client_order_id: string
  idempotency_key: string
  status: OrderStatus
  order_class: string
  symbol: string
  qty: number
  submitted_via: SubmittedVia
  legs: OrderLeg[]
  submitted_at: string | null
  updated_at: string
}

export interface Trade {
  id: string
  order_id: string
  fill_id: string
  qty: number
  price: string
  side: LegAction
  filled_at: string
}

// ---------------------------------------------------------------------------
// Decisions
// ---------------------------------------------------------------------------

export interface AiDecision {
  id: string
  agent_run_id: string
  strategy_id: string | null
  symbol: string
  agent_type: AgentType
  thesis: string
  confidence: string
  model_provider: string
  model_name: string
  prompt_version: string
  outcome: ProposalStatus | 'analyzed' | 'rejected'
  status: AgentRunStatus
  created_at: string
}

export interface DecisionFilters {
  symbol?: string
  strategy?: StrategyType
  agent?: AgentType
  outcome?: string
  date_from?: string
  date_to?: string
  min_confidence?: number
  status?: string
}

export interface TraceNode {
  agent_type: AgentType
  step: string
  started_at: string
  completed_at: string | null
  inputs: Record<string, unknown>
  outputs: Record<string, unknown>
  risk_rules?: RiskRuleResult[]
  tool_calls: { tool: string; args_summary: string; result_summary: string }[]
  errors: { message: string; retried: boolean }[]
}

export interface DecisionTrace {
  run_id: string
  decision_id: string
  nodes: TraceNode[]
  order_result: Order | null
  portfolio_result: { equity_after: string; position_id: string | null } | null
  rationale_summary: string
}

// ---------------------------------------------------------------------------
// Backtesting
// ---------------------------------------------------------------------------

export interface BacktestConfig {
  strategy: StrategyType
  symbols: string[]
  date_range: { from: string; to: string }
  starting_capital: string
  dte: { min: number; max: number }
  entry_rules: string
  exit_rules: string
  confidence_threshold: number
  slippage_pct: number
  risk_policy_version: number
}

export interface BacktestRun {
  id: string
  user_id: string
  config: BacktestConfig
  seed_hash: string
  status: BacktestStatus
  provenance: DataProvenance
  started_at: string
  completed_at: string | null
  progress_pct: number
}

export interface BacktestMetrics {
  win_rate: string
  profit_factor: string
  total_return: string
  max_drawdown: string
  trade_count: number
  sample_period: string
}

export interface BacktestResult {
  backtest_run_id: string
  metrics: BacktestMetrics
  equity_curve: EquityCurvePoint[]
  drawdown_curve: { t: string; drawdown_pct: number }[]
  return_distribution: { bucket: string; count: number }[]
  monthly_returns: { month: string; return_pct: number }[]
}

export interface BacktestTrade {
  id: string
  backtest_run_id: string
  symbol: string
  entry_at: string
  exit_at: string
  legs: StrategyLeg[]
  pnl: string
  exit_reason: string
}

export interface BacktestDetail extends BacktestRun {
  result: BacktestResult | null
  trades: BacktestTrade[]
}

// ---------------------------------------------------------------------------
// Settings / system health
// ---------------------------------------------------------------------------

export interface AlpacaConnection {
  environment: 'paper' | 'demo'
  status: 'not_configured' | 'connected' | 'error'
  account_id: string | null
  api_key_last4: string | null
  capabilities: string[]
  last_synced_at: string | null
}

export interface AppSettings {
  general: {
    timezone: string
    currency_display: string
    default_page: string
  }
  alpaca: AlpacaConnection
  ai_provider: {
    provider: string
    model: string
    configured: boolean
    key_fingerprint: string | null
  }
  risk: {
    active_policy_version: number
  }
  agents: {
    autonomy_mode: AutonomyMode
    confidence_threshold: string
  }
  notifications: UserSettings['notification_prefs']
  security: {
    two_factor_enabled: boolean
    active_sessions: number
  }
}

export interface SystemHealthComponent {
  key: string
  label: string
  status: SystemComponentStatus
  detail: string
  latency_ms: number | null
  checked_at: string
}

export interface SystemHealth {
  overall: SystemComponentStatus
  components: SystemHealthComponent[]
}

export interface MarketClock {
  is_open: boolean
  next_open: string
  next_close: string
  server_time: string
}
