import pytest

from app.alpaca.demo_adapter import DemoAlpacaAdapter


@pytest.mark.asyncio
async def test_capabilities_report_paper_verified():
    adapter = DemoAlpacaAdapter()
    caps = await adapter.get_capabilities()
    assert caps.paper_verified is True
    assert caps.mcp_connected is False  # demo adapter must never claim MCP handled anything


@pytest.mark.asyncio
async def test_same_symbol_produces_same_bars():
    adapter = DemoAlpacaAdapter()
    a = await adapter.get_bars("SPY")
    b = await adapter.get_bars("SPY")
    assert a.last_price == b.last_price
    assert [bar.close for bar in a.bars] == [bar.close for bar in b.bars]


@pytest.mark.asyncio
async def test_different_symbols_produce_different_series():
    adapter = DemoAlpacaAdapter()
    spy = await adapter.get_bars("SPY")
    nvda = await adapter.get_bars("NVDA")
    assert spy.last_price != nvda.last_price


@pytest.mark.asyncio
async def test_option_chain_is_internally_consistent():
    adapter = DemoAlpacaAdapter()
    chain = await adapter.get_option_chain("SPY", min_dte=20, max_dte=40)
    assert len(chain) > 0
    for c in chain:
        assert c.quote_bid <= c.quote_mid <= c.quote_ask
        assert c.occ_symbol.startswith("SPY")


@pytest.mark.asyncio
async def test_data_source_is_labeled_demo_fixture():
    adapter = DemoAlpacaAdapter()
    snapshot = await adapter.get_bars("SPY")
    assert snapshot.data_source == "demo_fixture"


@pytest.mark.asyncio
async def test_submitted_order_is_labeled_demo_never_pretends_real():
    adapter = DemoAlpacaAdapter()
    order = await adapter.submit_option_order(legs=[{"a": 1}], qty=1, limit_price=__import__("decimal").Decimal("1.00"), client_order_id="abc123")
    assert order.broker_order_id.startswith("demo-")
    assert "demo mode" in order.raw_response_sanitized["note"]
