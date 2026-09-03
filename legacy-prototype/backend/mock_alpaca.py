"""
mock_alpaca.py

DEMO / MOCK Alpaca adapter.

IMPORTANT (read this before touching this file):
This sandbox cannot reach alpaca.markets (network egress is restricted to a fixed
allowlist that does not include Alpaca's domains), and no real Alpaca API keys were
ever provided. Every method below returns synthetic, clearly-labeled data shaped to
approximate Alpaca's public REST schema from training knowledge -- it has NOT been
verified against Alpaca's live/current API docs. Treat every field name here as
"best-effort, unverified" until someone with real network access and a real paper
key checks it against https://docs.alpaca.markets.

This adapter NEVER calls a real endpoint. There is no code path in this class that
can reach a live trading account, by construction: there is no HTTP client here at
all, real or otherwise.
"""

from __future__ import annotations
import random
import time
import uuid
from dataclasses import dataclass, field, asdict


ADAPTER_MODE = "MOCK"  # the only value this can ever be in this build


class LiveTradingBlockedError(Exception):
    """Raised if any code path ever tries to set a live trading environment."""


def assert_paper_only(trading_env: str) -> None:
    if trading_env not in ("paper", "demo"):
        raise LiveTradingBlockedError(
            f"TRADING_ENV={trading_env!r} is not allowed. Only 'paper' or 'demo' "
            "are permitted in this build; there is no live-trading code path."
        )


@dataclass
class MockAccount:
    account_id: str = "MOCK-PAPER-0001"
    equity: float = 100000.00
    cash: float = 21879.55
    buying_power: float = 78120.45
    daily_pl: float = 0.0
    starting_equity: float = 100000.00
    currency: str = "USD"
    pattern_day_trader: bool = False
    trading_blocked: bool = False


class MockAlpacaAdapter:
    """
    Deterministic, in-memory stand-in for Alpaca's paper trading REST API.
    Every value produced here is simulated. Nothing here talks to a network.
    """

    def __init__(self, seed: int = 7):
        self._rng = random.Random(seed)
        self.mode = ADAPTER_MODE
        self.account = MockAccount()
        self._orders: dict[str, dict] = {}

    def get_account(self) -> dict:
        d = asdict(self.account)
        d["source"] = "mock"
        d["as_of"] = time.time()
        return d

    def get_clock(self) -> dict:
        # Simplified: always reports "open" for demo purposes so the UI has
        # something consistent to show. Real market-calendar logic is NOT
        # implemented in this build (see IMPLEMENTATION_STATUS.md).
        return {"is_open": True, "source": "mock", "as_of": time.time()}

    def submit_option_order(self, *, symbol: str, legs: list[dict], qty: int,
                             limit_price: float, idempotency_key: str) -> dict:
        """
        Simulates submitting a multi-leg options order. Fills immediately at
        (limit_price plus a small deterministic slippage) -- there is no real
        order book here, this is a paper-only simulation for demo purposes.
        """
        if idempotency_key in self._orders:
            existing = self._orders[idempotency_key]
            existing["idempotent_replay"] = True
            return existing

        slippage = round(limit_price * 0.002, 2)  # 0.2% simulated slippage
        fill_price = round(limit_price + slippage, 2)
        order = {
            "order_id": str(uuid.uuid4()),
            "client_order_id": idempotency_key,
            "symbol": symbol,
            "legs": legs,
            "qty": qty,
            "status": "filled",
            "limit_price": limit_price,
            "fill_price": fill_price,
            "filled_at": time.time(),
            "source": "mock",
            "idempotent_replay": False,
        }
        self._orders[idempotency_key] = order
        return order

    def list_orders(self) -> list[dict]:
        return list(self._orders.values())
