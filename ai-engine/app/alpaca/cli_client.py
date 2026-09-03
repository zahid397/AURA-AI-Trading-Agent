"""Safe wrapper around the official Alpaca Trading CLI (`alpaca`, distributed
via `go install github.com/alpacahq/cli/cmd/alpaca@latest` or
`brew install alpacahq/tap/cli` -- confirmed against
https://docs.alpaca.markets/us/docs/alpacas-cli at build time).

Used only for diagnostics/smoke checks (`scripts/alpaca-cli-smoke.sh`, the
Settings capability matrix), never as a trading execution path. Every
invocation:
  - uses a fixed executable name and an argv list (never a shell string,
    never string interpolation of caller-supplied text into a command),
  - is drawn from a hard-coded subcommand allowlist,
  - runs with a timeout,
  - explicitly forces `ALPACA_LIVE_TRADE` unset/false in the child
    environment regardless of the parent environment, and refuses to run at
    all if the parent environment has it set to a truthy value -- a second,
    independent guard against ever touching a live account from this path.
"""
from __future__ import annotations

import asyncio
import os
import shutil
from dataclasses import dataclass

_ALLOWED_SUBCOMMANDS: dict[str, list[str]] = {
    "version": ["version"],
    "doctor": ["doctor"],
    "account_get": ["account", "get"],
    "position_list": ["position", "list"],
    "option_contracts": ["option", "contracts"],  # caller must append --underlying-symbol via `extra_args`
}

_TRUTHY = {"1", "true", "yes", "on"}


class LiveTradingGuardError(RuntimeError):
    pass


@dataclass
class CliResult:
    ok: bool
    stdout: str
    stderr: str
    returncode: int | None


class AlpacaCliClient:
    channel_name = "alpaca_cli"
    binary_name = "alpaca"

    def __init__(self, api_key: str | None = None, secret_key: str | None = None, timeout_seconds: float = 10.0):
        if str(os.environ.get("ALPACA_LIVE_TRADE", "")).lower() in _TRUTHY:
            raise LiveTradingGuardError(
                "ALPACA_LIVE_TRADE is set to a truthy value in this process's environment. "
                "Refusing to construct AlpacaCliClient at all -- this build never runs the CLI in live mode."
            )
        self._api_key = api_key
        self._secret_key = secret_key
        self._timeout = timeout_seconds

    def is_installed(self) -> bool:
        return shutil.which(self.binary_name) is not None

    def _child_env(self) -> dict[str, str]:
        env = dict(os.environ)
        env.pop("ALPACA_LIVE_TRADE", None)  # never inherit; explicit paper-only below
        env["ALPACA_LIVE_TRADE"] = "false"
        if self._api_key:
            env["ALPACA_API_KEY"] = self._api_key
        if self._secret_key:
            env["ALPACA_SECRET_KEY"] = self._secret_key
        return env

    async def run(self, action: str, extra_args: list[str] | None = None) -> CliResult:
        if action not in _ALLOWED_SUBCOMMANDS:
            raise ValueError(f"Unsupported CLI action {action!r}; allowed: {sorted(_ALLOWED_SUBCOMMANDS)}")
        if not self.is_installed():
            return CliResult(ok=False, stdout="", stderr=f"{self.binary_name!r} is not installed on PATH", returncode=None)

        argv = [self.binary_name, *_ALLOWED_SUBCOMMANDS[action], *(extra_args or [])]
        try:
            proc = await asyncio.create_subprocess_exec(
                *argv,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                env=self._child_env(),
            )
            stdout_b, stderr_b = await asyncio.wait_for(proc.communicate(), timeout=self._timeout)
            return CliResult(
                ok=proc.returncode == 0,
                stdout=stdout_b.decode("utf-8", errors="replace"),
                stderr=stderr_b.decode("utf-8", errors="replace"),
                returncode=proc.returncode,
            )
        except TimeoutError:
            return CliResult(ok=False, stdout="", stderr=f"CLI call timed out after {self._timeout}s", returncode=None)
        except FileNotFoundError:
            return CliResult(ok=False, stdout="", stderr=f"{self.binary_name!r} not found", returncode=None)

    async def version(self) -> str | None:
        result = await self.run("version")
        return result.stdout.strip() if result.ok else None

    async def doctor(self) -> CliResult:
        return await self.run("doctor")
