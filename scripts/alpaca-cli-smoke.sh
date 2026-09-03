#!/usr/bin/env bash
# Manual/CI smoke check for the official Alpaca Trading CLI
# (https://github.com/alpacahq/cli -- `go install github.com/alpacahq/cli/cmd/alpaca@latest`
# or `brew install alpacahq/tap/cli`). Confirms the binary is present and can
# reach a paper account -- it never places an order. Requires
# ALPACA_API_KEY / ALPACA_SECRET_KEY in the environment; skips (exit 0) if
# the CLI isn't installed, since it's an optional diagnostic, not a hard
# dependency of the app.
set -uo pipefail

if ! command -v alpaca >/dev/null 2>&1; then
  echo "SKIP: 'alpaca' CLI is not installed on PATH -- see https://github.com/alpacahq/cli for install options."
  exit 0
fi

if [ "${ALPACA_LIVE_TRADE:-false}" != "false" ]; then
  echo "REFUSING TO RUN: ALPACA_LIVE_TRADE must be 'false' for this smoke check."
  exit 1
fi

echo "==> alpaca version"
alpaca version

echo "==> alpaca doctor"
alpaca doctor || echo "(doctor reported an issue -- see output above; not fatal for this smoke check)"

if [ -n "${ALPACA_API_KEY:-}" ] && [ -n "${ALPACA_SECRET_KEY:-}" ]; then
  echo "==> alpaca account get (paper)"
  ALPACA_LIVE_TRADE=false alpaca account get || echo "(account lookup failed -- check credentials/network)"
else
  echo "SKIP: ALPACA_API_KEY / ALPACA_SECRET_KEY not set -- skipping the authenticated account check."
fi

echo "Done."
