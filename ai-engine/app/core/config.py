"""Settings + the hard paper-only safety guard.

`TRADING_ENV` may only be `paper` or `demo`. `ALLOW_LIVE_TRADING` must be
absent or false. If a live Alpaca base URL is ever configured, the app must
refuse to start. This module is imported by `app/main.py` before anything
else -- the guard runs at import time, not lazily.
"""
from __future__ import annotations

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

LIVE_TRADING_HOST_MARKERS = ("api.alpaca.markets",)
PAPER_TRADING_HOST = "paper-api.alpaca.markets"


class ConfigurationError(RuntimeError):
    """Raised when startup configuration violates a hard safety rule."""


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    trading_env: str = "demo"
    allow_live_trading: bool = False
    alpaca_api_key: str | None = None
    alpaca_secret_key: str | None = None
    alpaca_base_url: str = "https://paper-api.alpaca.markets"
    alpaca_data_base_url: str = "https://data.alpaca.markets"
    alpaca_paper_trade: bool = True

    llm_provider: str = "fake"
    llm_api_key: str | None = None
    llm_model: str = "fake-deterministic-v1"

    internal_signing_secret: str = "dev-only-change-me"  # noqa: S105 -- an obviously-fake default, not a real secret
    laravel_internal_base_url: str = "http://localhost:8000/api/internal/v1"
    internal_timestamp_skew_seconds: int = 300

    langgraph_checkpoint_path: str = "./aura_langgraph_checkpoints.sqlite"

    @field_validator("trading_env")
    @classmethod
    def _validate_trading_env(cls, v: str) -> str:
        if v not in ("paper", "demo"):
            raise ConfigurationError(
                f"TRADING_ENV must be 'paper' or 'demo', got {v!r}. "
                "Live trading is not supported by this build."
            )
        return v

    @field_validator("allow_live_trading")
    @classmethod
    def _validate_allow_live_trading(cls, v: bool) -> bool:
        if v:
            raise ConfigurationError(
                "ALLOW_LIVE_TRADING must never be true. This build has a hard "
                "guard against live trading; there is no supported way to enable it."
            )
        return v

    @field_validator("alpaca_base_url")
    @classmethod
    def _validate_alpaca_base_url(cls, v: str) -> str:
        if any(marker in v for marker in LIVE_TRADING_HOST_MARKERS) and PAPER_TRADING_HOST not in v:
            raise ConfigurationError(
                f"ALPACA_BASE_URL {v!r} looks like a live-trading endpoint. "
                f"Only {PAPER_TRADING_HOST} is permitted."
            )
        return v

    @property
    def paper_verified(self) -> bool:
        return self.trading_env in ("paper", "demo") and not self.allow_live_trading and PAPER_TRADING_HOST in self.alpaca_base_url


def get_settings() -> Settings:
    """Constructing `Settings()` runs all validators above -- call this once
    at startup (`app/main.py`) so a bad config fails fast instead of failing
    on the first trade attempt."""
    return Settings()
