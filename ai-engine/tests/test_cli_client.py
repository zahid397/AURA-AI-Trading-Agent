
import pytest

from app.alpaca.cli_client import AlpacaCliClient, LiveTradingGuardError


def test_refuses_to_construct_when_live_trade_env_is_truthy(monkeypatch):
    monkeypatch.setenv("ALPACA_LIVE_TRADE", "true")
    with pytest.raises(LiveTradingGuardError):
        AlpacaCliClient()


def test_constructs_fine_when_live_trade_env_unset(monkeypatch):
    monkeypatch.delenv("ALPACA_LIVE_TRADE", raising=False)
    client = AlpacaCliClient()
    assert client.binary_name == "alpaca"


def test_child_env_always_forces_live_trade_false(monkeypatch):
    monkeypatch.delenv("ALPACA_LIVE_TRADE", raising=False)
    client = AlpacaCliClient(api_key="k", secret_key="s")
    env = client._child_env()
    assert env["ALPACA_LIVE_TRADE"] == "false"
    assert env["ALPACA_API_KEY"] == "k"


def test_rejects_unknown_action(monkeypatch):
    monkeypatch.delenv("ALPACA_LIVE_TRADE", raising=False)
    client = AlpacaCliClient()
    with pytest.raises(ValueError):
        import asyncio
        asyncio.run(client.run("not_a_real_action"))


@pytest.mark.asyncio
async def test_run_reports_not_installed_when_binary_missing(monkeypatch):
    monkeypatch.delenv("ALPACA_LIVE_TRADE", raising=False)
    client = AlpacaCliClient()
    monkeypatch.setattr(client, "is_installed", lambda: False)
    result = await client.run("version")
    assert result.ok is False
    assert "not installed" in result.stderr
