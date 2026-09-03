"""Official `alpaca-mcp-server` client -- the `alpaca_mcp` channel.

Spawns the real published package (`pip install alpaca-mcp-server`, PyPI
2.3.0 as of this build) via `uvx alpaca-mcp-server` over stdio, using the
official `mcp` Python SDK (`ClientSession` / `stdio_client`). Tool names are
**discovered** at connect time via `list_tools()`, never hard-coded as if
guaranteed -- `_TOOL_MAP` below is only a *preferred name* to look for; if a
name isn't present in the discovered set, that capability is reported
unavailable rather than silently failing at call time.

Each gateway call opens and tears down its own MCP session. That is a real
performance cost (subprocess spawn per call) traded for simplicity and
correctness in this build; a persistent session with an explicit
connect()/disconnect() lifecycle tied to the FastAPI app's startup/shutdown
would remove it and is the natural next optimization.
"""
from __future__ import annotations

import json
import os
from contextlib import asynccontextmanager
from datetime import UTC, datetime, timedelta
from decimal import Decimal
from typing import Any

from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client

from app.alpaca.gateway import (
    AccountSnapshot,
    MarketClock,
    OptionContract,
    PositionSnapshot,
    SubmittedOrder,
)
from app.workflows.state import AlpacaCapabilitySnapshot, MarketDataSnapshot

# Preferred tool names confirmed against the alpaca-mcp-server README at
# build time. Mapped to internal capability keys; actual availability is
# always re-checked via list_tools(), never assumed.
_TOOL_MAP = {
    "account_info": "get_account_info",
    "positions": "get_all_positions",
    "clock": "get_clock",
    "calendar": "get_calendar",
    "option_contracts": "get_option_contracts",
    "place_option_order": "place_option_order",
    "close_position": "close_position",
    "cancel_order": "cancel_order_by_id",
    "order_by_client_id": "get_order_by_client_id",
}


class McpUnavailableError(RuntimeError):
    pass


class McpAlpacaAdapter:
    channel_name = "alpaca_mcp"

    def __init__(self, api_key: str, secret_key: str, paper: bool = True):
        self._env = {
            **os.environ,
            "ALPACA_API_KEY": api_key,
            "ALPACA_SECRET_KEY": secret_key,
            "ALPACA_PAPER_TRADE": "true" if paper else "false",
        }
        if not paper:
            raise ValueError("McpAlpacaAdapter must never be constructed with paper=False")
        self._server_params = StdioServerParameters(command="uvx", args=["alpaca-mcp-server"], env=self._env)
        self._discovered_tools: set[str] | None = None

    @asynccontextmanager
    async def _session(self):
        async with stdio_client(self._server_params) as (read, write):
            async with ClientSession(read, write) as session:
                await session.initialize()
                yield session

    async def _discover(self, session: ClientSession) -> set[str]:
        result = await session.list_tools()
        names = {t.name for t in result.tools}
        self._discovered_tools = names
        return names

    async def _call(self, tool_key: str, arguments: dict) -> Any:
        preferred_name = _TOOL_MAP.get(tool_key)
        if preferred_name is None:
            raise McpUnavailableError(f"No known MCP tool mapping for capability {tool_key!r}")
        async with self._session() as session:
            available = await self._discover(session)
            if preferred_name not in available:
                raise McpUnavailableError(f"MCP server does not expose tool {preferred_name!r} (discovered: {sorted(available)})")
            result = await session.call_tool(preferred_name, arguments)
            text = result.content[0].text if result.content else "{}"
            return json.loads(text)

    async def get_capabilities(self) -> AlpacaCapabilitySnapshot:
        try:
            async with self._session() as session:
                available = await self._discover(session)
                connected = True
                tool_count = len(available)
        except Exception:
            connected = False
            tool_count = 0
        return AlpacaCapabilitySnapshot(
            trading_api_connected=connected,
            market_data_connected=connected,
            options_level=None,  # MCP doesn't expose this directly; the api adapter is the source of truth for it
            mcp_connected=connected,
            mcp_tool_count=tool_count,
            cli_available=False,
            cli_version=None,
            paper_verified=True,  # hard-coded: constructor raises if paper=False was ever passed
            checked_at=datetime.now(UTC),
        )

    async def get_account(self) -> AccountSnapshot:
        data = await self._call("account_info", {})
        return AccountSnapshot(
            equity=Decimal(str(data.get("equity", 0))),
            cash=Decimal(str(data.get("cash", 0))),
            buying_power=Decimal(str(data.get("buying_power", 0))),
            options_level=data.get("options_approved_level"),
            pattern_day_trader=bool(data.get("pattern_day_trader", False)),
            trading_blocked=bool(data.get("trading_blocked", False)),
        )

    async def get_clock(self) -> MarketClock:
        data = await self._call("clock", {})
        now = datetime.now(UTC)
        return MarketClock(
            is_open=bool(data.get("is_open", False)),
            next_open=data.get("next_open", now),
            next_close=data.get("next_close", now + timedelta(hours=6)),
            timestamp=now,
        )

    async def get_positions(self) -> list[PositionSnapshot]:
        data = await self._call("positions", {})
        items = data if isinstance(data, list) else data.get("positions", [])
        return [
            PositionSnapshot(
                symbol=p["symbol"], qty=Decimal(str(p["qty"])), avg_entry_price=Decimal(str(p["avg_entry_price"])),
                current_price=Decimal(str(p.get("current_price", 0))), market_value=Decimal(str(p.get("market_value", 0))),
                unrealized_pl=Decimal(str(p.get("unrealized_pl", 0))), asset_class=p.get("asset_class", "us_option"),
            )
            for p in items
        ]

    async def get_bars(self, symbol: str, lookback_days: int = 90) -> MarketDataSnapshot:
        raise McpUnavailableError("Bar history is served by the alpaca_api channel in this build; MCP is used for account/order/option-chain actions.")

    async def get_option_chain(self, underlying: str, min_dte: int, max_dte: int) -> list[OptionContract]:
        today = datetime.now(UTC).date()
        data = await self._call("option_contracts", {
            "underlying_symbol": underlying,
            "expiration_date_gte": str(today + timedelta(days=min_dte)),
            "expiration_date_lte": str(today + timedelta(days=max_dte)),
        })
        items = data if isinstance(data, list) else data.get("contracts", [])
        now = datetime.now(UTC)
        contracts = []
        for c in items:
            bid, ask = Decimal(str(c.get("bid_price", 0))), Decimal(str(c.get("ask_price", 0)))
            contracts.append(OptionContract(
                occ_symbol=c["symbol"], underlying=underlying, option_type=c.get("type", "call"),
                strike=Decimal(str(c["strike_price"])), expiration=c.get("expiration_date", today),
                quote_bid=bid, quote_ask=ask, quote_mid=(bid + ask) / 2,
                delta=Decimal(str(c["delta"])) if c.get("delta") is not None else None,
                gamma=Decimal(str(c["gamma"])) if c.get("gamma") is not None else None,
                theta=Decimal(str(c["theta"])) if c.get("theta") is not None else None,
                vega=Decimal(str(c["vega"])) if c.get("vega") is not None else None,
                open_interest=c.get("open_interest"), volume=c.get("volume"), quote_time=now,
            ))
        return contracts

    async def submit_option_order(
        self, legs: list[dict], qty: int, limit_price: Decimal, client_order_id: str, time_in_force: str = "day",
    ) -> SubmittedOrder:
        data = await self._call("place_option_order", {
            "legs": legs, "qty": qty, "limit_price": str(limit_price),
            "client_order_id": client_order_id, "time_in_force": time_in_force,
        })
        return SubmittedOrder(
            client_order_id=client_order_id, broker_order_id=data.get("id"),
            status=data.get("status", "new"), raw_response_sanitized={k: v for k, v in data.items() if "key" not in k.lower()},
        )

    async def get_order_by_client_id(self, client_order_id: str) -> SubmittedOrder | None:
        try:
            data = await self._call("order_by_client_id", {"client_order_id": client_order_id})
        except McpUnavailableError:
            return None
        return SubmittedOrder(client_order_id=client_order_id, broker_order_id=data.get("id"), status=data.get("status", "unknown"), raw_response_sanitized=data)

    async def cancel_order(self, broker_order_id: str) -> bool:
        await self._call("cancel_order", {"order_id": broker_order_id})
        return True

    async def close_position(self, symbol: str) -> bool:
        await self._call("close_position", {"symbol": symbol})
        return True
