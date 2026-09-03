"""Provider-neutral LLM adapter.

Agents use this only to *narrate* evidence that deterministic code already
computed (technical features, payoff math, risk rule results) into readable
theses/explanations. It never originates a number that matters financially --
callers pass the already-computed facts in and treat the returned text as
prose, not data. `FakeLLMProvider` is deterministic (template-based, no
network) and is the default for tests and `demo` mode. `AnthropicProvider` is
a real provider, called via a plain `httpx` POST (no extra SDK dependency)
against the public Messages API.
"""
from __future__ import annotations

from typing import Protocol

import httpx

ANTHROPIC_API_URL = "https://api.anthropic.com/v1/messages"
ANTHROPIC_API_VERSION = "2023-06-01"


class LLMProvider(Protocol):
    name: str

    async def generate(self, system: str, user: str, max_tokens: int = 600) -> str: ...


class FakeLLMProvider:
    """Deterministic, offline, template-based narration.

    Does not call an LLM at all -- it stitches the caller-supplied facts
    (already present in the `user` prompt, formatted as evidence lines) into
    plain sentences. Same input always produces the same output, which is
    what the test suite and `demo` mode need.
    """

    name = "fake-deterministic-v1"

    async def generate(self, system: str, user: str, max_tokens: int = 600) -> str:
        lines = [ln.strip("- ").strip() for ln in user.splitlines() if ln.strip()]
        evidence_lines = [ln for ln in lines if ":" in ln][:6]
        if not evidence_lines:
            return "No supporting evidence was supplied for this narration."
        body = "; ".join(evidence_lines)
        return f"Based on the observed evidence ({body}), the deterministic signals above support this assessment."


class AnthropicProvider:
    """Real provider: a direct HTTPS call to Anthropic's Messages API.

    Uses `httpx` directly rather than pulling in the `anthropic` SDK as a
    dependency -- this is a small, single-endpoint integration and the extra
    package isn't worth the weight for a hackathon build.
    """

    name: str

    def __init__(self, api_key: str, model: str, timeout_seconds: float = 20.0):
        if not api_key:
            raise ValueError("AnthropicProvider requires an API key")
        self._api_key = api_key
        self._model = model
        self.name = model
        self._timeout = timeout_seconds

    async def generate(self, system: str, user: str, max_tokens: int = 600) -> str:
        async with httpx.AsyncClient(timeout=self._timeout) as client:
            resp = await client.post(
                ANTHROPIC_API_URL,
                headers={
                    "x-api-key": self._api_key,
                    "anthropic-version": ANTHROPIC_API_VERSION,
                    "content-type": "application/json",
                },
                json={
                    "model": self._model,
                    "max_tokens": max_tokens,
                    "system": system,
                    "messages": [{"role": "user", "content": user}],
                },
            )
            resp.raise_for_status()
            data = resp.json()
            parts = data.get("content", [])
            return "".join(p.get("text", "") for p in parts if p.get("type") == "text").strip()


def get_llm_provider(provider: str, api_key: str | None, model: str) -> LLMProvider:
    """Factory used by `app/main.py` / dependency wiring. Falls back to the
    fake provider whenever a real one is requested but not configured, so a
    missing API key degrades to demo-quality narration instead of a crash."""
    if provider == "anthropic" and api_key:
        return AnthropicProvider(api_key=api_key, model=model)
    return FakeLLMProvider()
