"""Deterministic risk policy limits. Mirrors `risk_policies.limits` in
docs/db-schema.md exactly -- Laravel is the system of record for the active
version; the AI engine receives the version + limits snapshot on every run
request and must not mutate it."""
from __future__ import annotations

from decimal import Decimal

from pydantic import BaseModel, ConfigDict


class RiskPolicyLimits(BaseModel):
    model_config = ConfigDict(extra="forbid")

    min_confidence: Decimal = Decimal("0.55")
    max_loss_per_trade_pct_equity: Decimal = Decimal("0.02")
    max_position_risk_pct_equity: Decimal = Decimal("0.05")
    max_total_options_exposure_pct_equity: Decimal = Decimal("0.25")
    max_symbol_exposure_pct_equity: Decimal = Decimal("0.10")
    max_open_positions: int = 8
    max_daily_loss_pct_equity: Decimal = Decimal("0.03")
    max_drawdown_pct_equity: Decimal = Decimal("0.10")
    min_open_interest: int = 100
    min_volume: int = 10
    max_spread_pct: Decimal = Decimal("0.15")
    max_quote_age_seconds: int = 300
    min_dte: int = 7
    max_dte: int = 45


DEFAULT_RISK_POLICY_VERSION = 1
DEFAULT_RISK_POLICY = RiskPolicyLimits()
