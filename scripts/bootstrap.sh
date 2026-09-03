#!/usr/bin/env bash
# One-time local setup: copies .env files and installs dependencies for
# whichever services you have a runtime for. Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> Copying .env files (skips any that already exist)"
cp -n .env.example .env 2>/dev/null || true
cp -n backend/.env.example backend/.env 2>/dev/null || true
cp -n ai-engine/.env.example ai-engine/.env 2>/dev/null || true

if command -v php >/dev/null 2>&1; then
  echo "==> Installing Laravel dependencies"
  (cd backend && php "$(command -v composer)" install)
else
  echo "==> Skipping Laravel composer install (no php on PATH -- use Docker Compose instead: 'make up')"
fi

if command -v python3 >/dev/null 2>&1 || command -v python >/dev/null 2>&1; then
  PYBIN=$(command -v python3 || command -v python)
  echo "==> Setting up ai-engine virtualenv"
  (cd ai-engine && "$PYBIN" -m venv .venv && \
    ./.venv/*/python -m pip install --upgrade pip && \
    ./.venv/*/python -m pip install fastapi uvicorn "pydantic>=2" pydantic-settings \
      langgraph langgraph-checkpoint-sqlite alpaca-py mcp httpx python-dotenv pytest pytest-asyncio)
fi

if command -v npm >/dev/null 2>&1; then
  echo "==> Installing frontend dependencies"
  (cd frontend && npm ci)
fi

echo "==> Done. Next: 'make up' (Docker) or run each service locally per its README."
