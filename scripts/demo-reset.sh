#!/usr/bin/env bash
# Recreates the deterministic demo scenario from scratch. Safe to run
# repeatedly -- it always produces the same starting numbers (see
# backend/database/seeders/DemoSeeder.php).
set -euo pipefail
cd "$(dirname "$0")/.."

if docker compose ps laravel >/dev/null 2>&1 && [ -n "$(docker compose ps -q laravel 2>/dev/null)" ]; then
  echo "==> Resetting demo data via the running Laravel container"
  docker compose exec laravel php artisan aura:demo-reset
else
  echo "==> No running 'laravel' container found; resetting via local PHP instead"
  (cd backend && php artisan aura:demo-reset)
fi
