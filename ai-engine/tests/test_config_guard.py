import pytest

from app.core.config import ConfigurationError, Settings


def test_default_settings_are_paper_and_verified():
    s = Settings(_env_file=None)
    assert s.trading_env == "demo"
    assert s.paper_verified is True


def test_rejects_live_trading_env():
    with pytest.raises(ConfigurationError):
        Settings(_env_file=None, trading_env="live")


def test_rejects_allow_live_trading_true():
    with pytest.raises(ConfigurationError):
        Settings(_env_file=None, allow_live_trading=True)


def test_rejects_live_alpaca_base_url():
    with pytest.raises(ConfigurationError):
        Settings(_env_file=None, alpaca_base_url="https://api.alpaca.markets")


def test_accepts_explicit_paper_url():
    s = Settings(_env_file=None, alpaca_base_url="https://paper-api.alpaca.markets")
    assert s.paper_verified is True
