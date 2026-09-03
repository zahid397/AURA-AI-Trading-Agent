#!/usr/bin/env bash
# Hits every service's health endpoint and reports pass/fail -- no arbitrary
# sleeps, just direct checks. Exit code is non-zero if anything is down.
set -uo pipefail
cd "$(dirname "$0")/.."

fail=0

check() {
  local name="$1" url="$2"
  if curl -fsS --max-time 5 "$url" >/dev/null 2>&1; then
    echo "OK   $name ($url)"
  else
    echo "FAIL $name ($url)"
    fail=1
  fi
}

check "Frontend (nginx)" "http://localhost:8080/"
check "Laravel (/up)"    "http://localhost:8000/up"
check "AI Engine (/health)" "http://localhost:8001/health"
check "AI Engine (/ready)"  "http://localhost:8001/ready"

exit $fail
