"""Deterministic demo/fixture adapter -- no network call, ever.

Ported from legacy-prototype/backend/market_math.synthetic_price_series
(same seeding approach: hash the symbol name so the same symbol always
produces the same series) and extended to also synthesize a plausible,
internally-consistent option chain priced with the real Black-Scholes
implementation in `app/indicators/options_pricing.py` -- premiums are
genuinely computed from the synthetic spot/vol, not hand-picked numbers.
Open interest/volume/spread are deterministic pseudo-random (seeded), never
real liquidity data -- this adapter must never be mistaken for `alpaca_api`.
"""
from __future__ import annotations

import random
from datetime import UTC, date, datetime, timedelta
from decimal import Decimal

from app.alpaca.gateway import (
    AccountSnapshot,
    MarketClock,
    OptionContract,
    PositionSnapshot,
    SubmittedOrder,
)
from app.indicators.options_pricing import black_scholes
from app.workflows.state import AlpacaCapabilitySnapshot, Bar, MarketDataSnapshot

DEMO_STARTING_EQUITY = Decimal("100000.00")
DEMO_CURRENT_BALANCE = Decimal("100245.69")
DEMO_BUYING_POWER = Decimal("78120.45")


def _seed_for(symbol: str) -> int:
    return sum(ord(c) for c in symbol) * 1000


def synthetic_price_series(symbol: str, days: int = 90, start_price: float | None = None) -> list[float]:
    """Seeded by `symbol` alone (not `days`) so a longer request is always a
    strict extension of a shorter one -- `synthetic_price_series(s, 200)[:150]
    == synthetic_price_series(s, 150)`. This matters for the backtest engine's
    no-look-ahead guarantee: widening the backtest window must never change
    the price path (and therefore the trade decisions) on earlier days."""
    rng = random.Random(_seed_for(symbol))
    price = start_price if start_price is not None else 100 + (_seed_for(symbol) % 400)
    prices = [price]
    for _ in range(days - 1):
        daily_return = rng.gauss(0.0003, 0.015)
        price = max(1.0, price * (1 + daily_return))
        prices.append(round(price, 2))
    return prices


class DemoAlpacaAdapter:
    channel_name = "demo"

    def __init__(self, seed_anchor: date | None = None):
        self.seed_anchor = seed_anchor or datetime.now(UTC).date()
        # In-process only (not persisted) -- but it must exist at all, otherwise
        # `get_order_by_client_id` has no way to distinguish "never submitted"
        # from "already submitted", which would make every submission look
        # like an idempotent replay of one that never actually happened.
        self._orders: dict[str, SubmittedOrder] = {}

    async def get_capabilities(self) -> AlpacaCapabilitySnapshot:
        return AlpacaCapabilitySnapshot(
            trading_api_connected=True,
            market_data_connected=True,
            options_level=3,
            mcp_connected=False,
            mcp_tool_count=0,
            cli_available=False,
            cli_version=None,
            paper_verified=True,
            checked_at=datetime.now(UTC),
        )

    async def get_account(self) -> AccountSnapshot:
        return AccountSnapshot(
            equity=DEMO_CURRENT_BALANCE,
            cash=DEMO_BUYING_POWER,
            buying_power=DEMO_BUYING_POWER,
            options_level=3,
            daily_realized_pl=Decimal("63.35"),
            daily_unrealized_pl=Decimal("182.34"),
            pattern_day_trader=False,
            trading_blocked=False,
        )

    async def get_clock(self) -> MarketClock:
        now = datetime.now(UTC)
        return MarketClock(is_open=True, next_open=now, next_close=now + timedelta(hours=6), timestamp=now)

    async def get_positions(self) -> list[PositionSnapshot]:
        return []  # Laravel is the system of record for positions; the demo adapter only fabricates market/chain data.

    async def get_bars(self, symbol: str, lookback_days: int = 90) -> MarketDataSnapshot:
        prices = synthetic_price_series(symbol, days=lookback_days)
        now = datetime.now(UTC)
        bars = [
            Bar(
                t=now - timedelta(days=lookback_days - i),
                open=Decimal(str(p)), high=Decimal(str(round(p * 1.01, 2))),
                low=Decimal(str(round(p * 0.99, 2))), close=Decimal(str(p)),
                volume=1_000_000 + (_seed_for(symbol) % 500_000),
            )
            for i, p in enumerate(prices)
        ]
        last = prices[-1]
        return MarketDataSnapshot(
            symbol=symbol, last_price=Decimal(str(last)), bars=bars,
            quote_bid=Decimal(str(round(last - 0.02, 2))), quote_ask=Decimal(str(round(last + 0.02, 2))),
            quote_time=now, is_stale=False, data_source="demo_fixture",
        )

    async def get_option_chain(self, underlying: str, min_dte: int, max_dte: int) -> list[OptionContract]:
        prices = synthetic_price_series(underlying, days=90)
        spot = prices[-1]
        vol = 0.22 + ((_seed_for(underlying) % 20) / 100)  # deterministic 22-42% IV band
        dte = (min_dte + max_dte) // 2
        expiration = self.seed_anchor + timedelta(days=dte)
        now = datetime.now(UTC)
        rng = random.Random(_seed_for(underlying) + dte)

        strike_step = 1 if spot < 50 else (5 if spot < 200 else 10)
        strikes = [round((spot // strike_step + offset) * strike_step, 2) for offset in range(-10, 11)]

        contracts: list[OptionContract] = []
        for strike in strikes:
            for option_type in ("call", "put"):
                priced = black_scholes(spot, strike, dte, vol, option_type=option_type)
                fair_value = max(priced.price, Decimal("0.01"))
                spread = max(Decimal("0.01"), fair_value * Decimal("0.04"))
                bid = max(Decimal("0.01"), fair_value - spread / 2)
                ask = bid + spread  # guarantees ask > bid even when fair_value floors to the minimum
                mid = (bid + ask) / 2
                occ_date = expiration.strftime("%y%m%d")
                occ_strike = f"{int(strike * 1000):08d}"
                occ_symbol = f"{underlying}{occ_date}{'C' if option_type == 'call' else 'P'}{occ_strike}"
                contracts.append(OptionContract(
                    occ_symbol=occ_symbol, underlying=underlying, option_type=option_type,
                    strike=Decimal(str(strike)), expiration=expiration,
                    quote_bid=bid, quote_ask=ask, quote_mid=mid,
                    delta=priced.delta, gamma=priced.gamma, theta=priced.theta, vega=priced.vega,
                    open_interest=rng.randint(50, 5000), volume=rng.randint(0, 800),
                    quote_time=now,
                ))
        return contracts

    async def submit_option_order(
        self, legs: list[dict], qty: int, limit_price: Decimal, client_order_id: str, time_in_force: str = "day",
    ) -> SubmittedOrder:
        order = SubmittedOrder(
            client_order_id=client_order_id,
            broker_order_id=f"demo-{client_order_id}",
            status="filled",
            raw_response_sanitized={"note": "demo mode -- no real broker call was made", "legs": len(legs), "qty": qty},
        )
        self._orders[client_order_id] = order
        return order

    async def get_order_by_client_id(self, client_order_id: str) -> SubmittedOrder | None:
        return self._orders.get(client_order_id)

    async def cancel_order(self, broker_order_id: str) -> bool:
        return True

    async def close_position(self, symbol: str) -> bool:
        return True
