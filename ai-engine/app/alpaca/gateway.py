"""AlpacaGateway: one interface, four adapters (demo / api / mcp / cli).

Every adapter implements this same `Protocol` so the agents never know which
channel actually served a request. Every order/account operation must record
which channel handled it (`submitted_via`) -- see `app/workflows/state.py`'s
`SubmissionChannel`. Nothing in `app/agents/` should import a concrete
adapter directly; they only see this interface via dependency injection in
`app/workflows/trading_graph.py`.
"""
from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal
from typing import Protocol

from pydantic import BaseModel

from app.workflows.state import (
    AlpacaCapabilitySnapshot,
    MarketDataSnapshot,
)


class AccountSnapshot(BaseModel):
    equity: Decimal
    cash: Decimal
    buying_power: Decimal
    options_level: int | None = None
    daily_realized_pl: Decimal = Decimal(0)
    daily_unrealized_pl: Decimal = Decimal(0)
    pattern_day_trader: bool = False
    trading_blocked: bool = False


class MarketClock(BaseModel):
    is_open: bool
    next_open: datetime
    next_close: datetime
    timestamp: datetime


class OptionContract(BaseModel):
    """A single raw chain entry -- no side/position_intent/ratio_qty yet;
    the strategy selector turns chosen contracts into `OptionLeg`s."""
    occ_symbol: str
    underlying: str
    option_type: str  # "call" | "put"
    strike: Decimal
    expiration: date
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


class PositionSnapshot(BaseModel):
    symbol: str
    qty: Decimal
    avg_entry_price: Decimal
    current_price: Decimal
    market_value: Decimal
    unrealized_pl: Decimal
    asset_class: str  # "us_option" | "us_equity"


class SubmittedOrder(BaseModel):
    client_order_id: str
    broker_order_id: str | None = None
    status: str
    raw_response_sanitized: dict


class AlpacaGateway(Protocol):
    channel_name: str  # "demo" | "alpaca_api" | "alpaca_mcp" | "alpaca_cli"

    async def get_capabilities(self) -> AlpacaCapabilitySnapshot: ...

    async def get_account(self) -> AccountSnapshot: ...

    async def get_clock(self) -> MarketClock: ...

    async def get_positions(self) -> list[PositionSnapshot]: ...

    async def get_bars(self, symbol: str, lookback_days: int = 90) -> MarketDataSnapshot: ...

    async def get_option_chain(
        self, underlying: str, min_dte: int, max_dte: int
    ) -> list[OptionContract]: ...

    async def submit_option_order(
        self,
        legs: list[dict],
        qty: int,
        limit_price: Decimal,
        client_order_id: str,
        time_in_force: str = "day",
    ) -> SubmittedOrder: ...

    async def get_order_by_client_id(self, client_order_id: str) -> SubmittedOrder | None: ...

    async def cancel_order(self, broker_order_id: str) -> bool: ...

    async def close_position(self, symbol: str) -> bool: ...
