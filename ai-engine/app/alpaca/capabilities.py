"""Builds the unified capability snapshot shown on Settings/System Health.

Never claims a channel is connected without actually probing it this call --
no caching a stale "yes" across restarts. A short in-process TTL cache is
reasonable to add later (the product spec allows "cached briefly" from
Laravel's side); this module itself always probes fresh.
"""
from __future__ import annotations

from datetime import UTC, datetime

from app.alpaca.cli_client import AlpacaCliClient
from app.core.config import Settings
from app.workflows.state import AlpacaCapabilitySnapshot


async def build_capability_snapshot(settings: Settings) -> AlpacaCapabilitySnapshot:
    trading_ok = False
    market_data_ok = False
    options_level: int | None = None

    if settings.alpaca_api_key and settings.alpaca_secret_key:
        from app.alpaca.trading_api import (
            AlpacaApiAdapter,  # local import: alpaca-py is heavy, only needed here
        )
        try:
            api = AlpacaApiAdapter(settings.alpaca_api_key, settings.alpaca_secret_key)
            api_caps = await api.get_capabilities()
            trading_ok = api_caps.trading_api_connected
            market_data_ok = api_caps.market_data_connected
            options_level = api_caps.options_level
        except Exception:
            pass

    mcp_connected = False
    mcp_tool_count = 0
    if settings.alpaca_api_key and settings.alpaca_secret_key:
        from app.alpaca.mcp_client import McpAlpacaAdapter
        try:
            mcp = McpAlpacaAdapter(settings.alpaca_api_key, settings.alpaca_secret_key, paper=True)
            mcp_caps = await mcp.get_capabilities()
            mcp_connected = mcp_caps.mcp_connected
            mcp_tool_count = mcp_caps.mcp_tool_count
        except Exception:
            pass

    cli = AlpacaCliClient(settings.alpaca_api_key, settings.alpaca_secret_key)
    cli_available = cli.is_installed()
    cli_version = await cli.version() if cli_available else None

    return AlpacaCapabilitySnapshot(
        trading_api_connected=trading_ok,
        market_data_connected=market_data_ok,
        options_level=options_level,
        mcp_connected=mcp_connected,
        mcp_tool_count=mcp_tool_count,
        cli_available=cli_available,
        cli_version=cli_version,
        paper_verified=settings.paper_verified,
        checked_at=datetime.now(UTC),
    )
