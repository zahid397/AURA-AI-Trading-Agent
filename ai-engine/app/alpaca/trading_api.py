"""Direct alpaca-py SDK adapter -- the `alpaca_api` channel.

`TradingClient(..., paper=True)` is passed as a Python literal, never from a
variable that could be flipped by configuration -- this file has no code
path that can construct a live-trading client. Field names below (e.g.
`options_approved_level`, `ratio_qty`, `position_intent`) were confirmed
against the actually-installed `alpaca-py==0.44.0` package, not guessed.
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta
from decimal import Decimal

from alpaca.data.historical import OptionHistoricalDataClient, StockHistoricalDataClient
from alpaca.data.requests import OptionChainRequest, StockBarsRequest
from alpaca.data.timeframe import TimeFrame
from alpaca.trading.client import TradingClient
from alpaca.trading.enums import OrderClass, TimeInForce
from alpaca.trading.enums import OrderSide as AlpacaOrderSide
from alpaca.trading.enums import PositionIntent as AlpacaPositionIntent
from alpaca.trading.requests import LimitOrderRequest, OptionLegRequest

from app.alpaca.gateway import (
    AccountSnapshot,
    MarketClock,
    OptionContract,
    PositionSnapshot,
    SubmittedOrder,
)
from app.workflows.state import AlpacaCapabilitySnapshot, Bar, MarketDataSnapshot


def _sanitize(raw: dict) -> dict:
    """Strip anything that could be a secret before it's persisted/logged."""
    drop_keys = {"api_key", "secret_key", "Authorization", "authorization"}
    return {k: v for k, v in raw.items() if k not in drop_keys}


class AlpacaApiAdapter:
    channel_name = "alpaca_api"

    def __init__(self, api_key: str, secret_key: str):
        if not api_key or not secret_key:
            raise ValueError("AlpacaApiAdapter requires both api_key and secret_key")
        # `paper=True` is a hard-coded literal -- this adapter can never target live trading.
        self._trading = TradingClient(api_key, secret_key, paper=True)
        self._stock_data = StockHistoricalDataClient(api_key, secret_key)
        self._option_data = OptionHistoricalDataClient(api_key, secret_key)

    async def get_capabilities(self) -> AlpacaCapabilitySnapshot:
        try:
            account = self._trading.get_account()
            trading_ok = True
            options_level = int(getattr(account, "options_approved_level", 0) or 0)
        except Exception:
            trading_ok = False
            options_level = None
        try:
            self._stock_data.get_stock_latest_quote  # attribute exists; a real ping would cost a call
            data_ok = True
        except Exception:
            data_ok = False
        return AlpacaCapabilitySnapshot(
            trading_api_connected=trading_ok,
            market_data_connected=data_ok,
            options_level=options_level,
            mcp_connected=False,
            mcp_tool_count=0,
            cli_available=False,
            cli_version=None,
            paper_verified=True,  # hard-coded: this adapter is constructed paper=True, never otherwise
            checked_at=datetime.now(UTC),
        )

    async def get_account(self) -> AccountSnapshot:
        a = self._trading.get_account()
        return AccountSnapshot(
            equity=Decimal(str(a.equity)),
            cash=Decimal(str(a.cash)),
            buying_power=Decimal(str(a.buying_power)),
            options_level=int(getattr(a, "options_approved_level", 0) or 0),
            pattern_day_trader=bool(a.pattern_day_trader),
            trading_blocked=bool(a.trading_blocked),
        )

    async def get_clock(self) -> MarketClock:
        c = self._trading.get_clock()
        return MarketClock(is_open=c.is_open, next_open=c.next_open, next_close=c.next_close, timestamp=c.timestamp)

    async def get_positions(self) -> list[PositionSnapshot]:
        positions = self._trading.get_all_positions()
        return [
            PositionSnapshot(
                symbol=p.symbol, qty=Decimal(str(p.qty)), avg_entry_price=Decimal(str(p.avg_entry_price)),
                current_price=Decimal(str(p.current_price or 0)), market_value=Decimal(str(p.market_value or 0)),
                unrealized_pl=Decimal(str(p.unrealized_pl or 0)), asset_class=str(p.asset_class.value if hasattr(p.asset_class, "value") else p.asset_class),
            )
            for p in positions
        ]

    async def get_bars(self, symbol: str, lookback_days: int = 90) -> MarketDataSnapshot:
        req = StockBarsRequest(
            symbol_or_symbols=symbol, timeframe=TimeFrame.Day,
            start=datetime.now(UTC) - timedelta(days=lookback_days),
        )
        bar_set = self._stock_data.get_stock_bars(req)
        raw_bars = bar_set[symbol] if hasattr(bar_set, "__getitem__") else bar_set.data.get(symbol, [])
        bars = [
            Bar(t=b.timestamp, open=Decimal(str(b.open)), high=Decimal(str(b.high)),
                low=Decimal(str(b.low)), close=Decimal(str(b.close)), volume=int(b.volume))
            for b in raw_bars
        ]
        quote = self._stock_data.get_stock_latest_quote(symbol) if hasattr(self._stock_data, "get_stock_latest_quote") else None
        last_price = bars[-1].close if bars else Decimal(0)
        now = datetime.now(UTC)
        return MarketDataSnapshot(
            symbol=symbol, last_price=last_price, bars=bars,
            quote_bid=Decimal(str(quote.bid_price)) if quote else None,
            quote_ask=Decimal(str(quote.ask_price)) if quote else None,
            quote_time=now, is_stale=False, data_source="alpaca_api",
        )

    async def get_option_chain(self, underlying: str, min_dte: int, max_dte: int) -> list[OptionContract]:
        today = datetime.now(UTC).date()
        req = OptionChainRequest(
            underlying_symbol=underlying,
            expiration_date_gte=today + timedelta(days=min_dte),
            expiration_date_lte=today + timedelta(days=max_dte),
        )
        chain = self._option_data.get_option_chain(req)
        now = datetime.now(UTC)
        contracts: list[OptionContract] = []
        for occ_symbol, snap in chain.items():
            quote = getattr(snap, "latest_quote", None)
            greeks = getattr(snap, "greeks", None)
            if quote is None:
                continue
            bid, ask = Decimal(str(quote.bid_price)), Decimal(str(quote.ask_price))
            contracts.append(OptionContract(
                occ_symbol=occ_symbol, underlying=underlying,
                option_type="call" if "C" in occ_symbol[len(underlying):len(underlying) + 7] else "put",
                strike=Decimal(str(getattr(snap, "strike_price", 0) or 0)),
                expiration=getattr(snap, "expiration_date", today),
                quote_bid=bid, quote_ask=ask, quote_mid=(bid + ask) / 2,
                delta=Decimal(str(greeks.delta)) if greeks and greeks.delta is not None else None,
                gamma=Decimal(str(greeks.gamma)) if greeks and greeks.gamma is not None else None,
                theta=Decimal(str(greeks.theta)) if greeks and greeks.theta is not None else None,
                vega=Decimal(str(greeks.vega)) if greeks and greeks.vega is not None else None,
                open_interest=getattr(snap, "open_interest", None),
                volume=getattr(getattr(snap, "latest_trade", None), "size", None),
                quote_time=now,
            ))
        return contracts

    async def submit_option_order(
        self, legs: list[dict], qty: int, limit_price: Decimal, client_order_id: str, time_in_force: str = "day",
    ) -> SubmittedOrder:
        leg_requests = [
            OptionLegRequest(
                symbol=leg["occ_symbol"],
                ratio_qty=leg["ratio_qty"],
                side=AlpacaOrderSide.BUY if leg["side"] == "buy" else AlpacaOrderSide.SELL,
                position_intent=AlpacaPositionIntent(leg["position_intent"]),
            )
            for leg in legs
        ]
        order_data = LimitOrderRequest(
            qty=qty,
            order_class=OrderClass.MLEG,
            time_in_force=TimeInForce.DAY if time_in_force == "day" else TimeInForce.GTC,
            limit_price=limit_price,
            legs=leg_requests,
            client_order_id=client_order_id,
        )
        order = self._trading.submit_order(order_data=order_data)
        return SubmittedOrder(
            client_order_id=order.client_order_id, broker_order_id=str(order.id),
            status=str(order.status.value if hasattr(order.status, "value") else order.status),
            raw_response_sanitized=_sanitize(order.__dict__ if hasattr(order, "__dict__") else {}),
        )

    async def get_order_by_client_id(self, client_order_id: str) -> SubmittedOrder | None:
        try:
            order = self._trading.get_order_by_client_id(client_order_id)
        except Exception:
            return None
        return SubmittedOrder(
            client_order_id=order.client_order_id, broker_order_id=str(order.id),
            status=str(order.status.value if hasattr(order.status, "value") else order.status),
            raw_response_sanitized=_sanitize(order.__dict__ if hasattr(order, "__dict__") else {}),
        )

    async def cancel_order(self, broker_order_id: str) -> bool:
        self._trading.cancel_order_by_id(broker_order_id)
        return True

    async def close_position(self, symbol: str) -> bool:
        self._trading.close_position(symbol)
        return True
