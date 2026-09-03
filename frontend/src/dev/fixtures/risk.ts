import type { RiskAlert, RiskOverview, RiskPolicy, RiskRuleResult } from '@/types/api'
import { isoDaysFromAnchor } from './demo'

export const RISK_POLICY: RiskPolicy = {
  id: 'risk-policy-v1',
  version: 1,
  name: 'Default Paper Trading Policy',
  active: true,
  created_by: null,
  created_at: isoDaysFromAnchor(-14),
  limits: {
    max_daily_loss_pct: 3.0,
    max_drawdown_pct: 10.0,
    max_position_risk_pct: 2.0,
    max_total_exposure_pct: 50.0,
    max_symbol_exposure_pct: 15.0,
    min_confidence: 0.65,
    max_quote_age_seconds: 60,
    min_open_interest: 100,
    max_spread_pct: 10.0,
    max_open_positions: 10,
  },
}

export const RISK_POLICY_HISTORY: RiskPolicy[] = [RISK_POLICY]

export const RISK_OVERVIEW: RiskOverview = {
  risk_score: 18,
  risk_level: 'LOW',
  daily_pl: '96.40',
  daily_loss_limit: '3007.37',
  exposure_pct: 1.04,
  max_exposure_pct: 25,
  position_sizing_budget: '5012.28',
  drawdown_pct: 0.4,
  max_drawdown_pct: 10,
  remaining_capacity: '24187.66',
  kill_switch_engaged: false,
  exposure_by_underlying: [{ symbol: 'SPY', exposure: '1042.34', pct: 1.04 }],
  exposure_by_strategy: [{ strategy_type: 'bull_call_spread', exposure: '1042.34', pct: 1.04 }],
  drawdown_series: Array.from({ length: 20 }, (_, i) => ({
    t: isoDaysFromAnchor(i - 19),
    drawdown_pct: Math.max(0, 1.8 - i * 0.09 + Math.sin(i / 3) * 0.5),
  })),
}

export const RISK_ALERTS: RiskAlert[] = [
  {
    id: 'alert-1',
    severity: 'critical',
    title: 'Proposal rejected: symbol exposure limit',
    message: 'TSLA Covered Call proposal rejected -- modeled single-symbol exposure of 11.4% would exceed the 10% policy limit.',
    created_at: isoDaysFromAnchor(0),
    acknowledged_at: null,
    related_symbol: 'TSLA',
  },
  {
    id: 'alert-2',
    severity: 'warning',
    title: 'Proposal awaiting human approval',
    message: 'NVDA Iron Condor is pending approval under the current approval_required autonomy mode.',
    created_at: isoDaysFromAnchor(0),
    acknowledged_at: null,
    related_symbol: 'NVDA',
  },
  {
    id: 'alert-3',
    severity: 'info',
    title: 'Trade executed automatically',
    message: 'SPY Bull Call Spread cleared all risk gates and was submitted to Alpaca paper trading.',
    created_at: isoDaysFromAnchor(0),
    acknowledged_at: isoDaysFromAnchor(0),
    related_symbol: 'SPY',
  },
]

export const SPY_RULE_RESULTS: RiskRuleResult[] = [
  { id: 'rr-spy-1', rule_key: 'min_confidence', description: 'Minimum model confidence', observed_value: '87%', limit_value: '55%', passed: true, severity: 'info', explanation: 'Confidence comfortably clears the policy floor.' },
  { id: 'rr-spy-2', rule_key: 'max_loss_per_trade_pct_equity', description: 'Max loss per trade (% of equity)', observed_value: '0.86%', limit_value: '2.00%', passed: true, severity: 'info', explanation: 'Net debit risk is well inside the per-trade cap.' },
  { id: 'rr-spy-3', rule_key: 'max_symbol_exposure_pct_equity', description: 'Max exposure to a single underlying', observed_value: '1.04%', limit_value: '10.00%', passed: true, severity: 'info', explanation: 'SPY exposure is far below the concentration limit.' },
  { id: 'rr-spy-4', rule_key: 'min_open_interest', description: 'Minimum open interest per leg', observed_value: '18,420', limit_value: '100', passed: true, severity: 'info', explanation: 'Both legs are deeply liquid.' },
  { id: 'rr-spy-5', rule_key: 'max_quote_age_seconds', description: 'Maximum quote staleness', observed_value: '12s', limit_value: '300s', passed: true, severity: 'info', explanation: 'Quotes were fresh at evaluation time.' },
]

export const NVDA_RULE_RESULTS: RiskRuleResult[] = [
  { id: 'rr-nvda-1', rule_key: 'min_confidence', description: 'Minimum model confidence', observed_value: '76%', limit_value: '55%', passed: true, severity: 'info', explanation: 'Confidence clears the policy floor.' },
  { id: 'rr-nvda-2', rule_key: 'max_loss_per_trade_pct_equity', description: 'Max loss per trade (% of equity)', observed_value: '0.36%', limit_value: '2.00%', passed: true, severity: 'info', explanation: 'Defined-risk structure keeps loss well contained.' },
  { id: 'rr-nvda-3', rule_key: 'max_total_options_exposure_pct_equity', description: 'Total options exposure (% of equity)', observed_value: '22.10%', limit_value: '25.00%', passed: true, severity: 'warning', explanation: 'Adding this position would bring total exposure close to the policy ceiling -- flagged for visibility.' },
  { id: 'rr-nvda-4', rule_key: 'autonomy_mode_gate', description: 'Autonomy mode requires human approval', observed_value: 'approval_required', limit_value: 'auto-execute disabled', passed: true, severity: 'warning', explanation: 'Account is in approval_required mode, so this otherwise-approved proposal is held for a human decision before execution.' },
]

export const TSLA_RULE_RESULTS: RiskRuleResult[] = [
  { id: 'rr-tsla-1', rule_key: 'min_confidence', description: 'Minimum model confidence', observed_value: '81%', limit_value: '55%', passed: true, severity: 'info', explanation: 'Confidence clears the policy floor.' },
  { id: 'rr-tsla-2', rule_key: 'max_symbol_exposure_pct_equity', description: 'Max exposure to a single underlying', observed_value: '11.40%', limit_value: '10.00%', passed: false, severity: 'critical', explanation: 'Modeled TSLA exposure (existing + this covered call’s share-equivalent basis) would reach 11.4% of equity, exceeding the 10% single-symbol concentration limit.' },
  { id: 'rr-tsla-3', rule_key: 'min_open_interest', description: 'Minimum open interest per leg', observed_value: '14,220', limit_value: '100', passed: true, severity: 'info', explanation: 'Short call leg is liquid.' },
]
