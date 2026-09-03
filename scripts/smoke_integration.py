"""One-off manual integration smoke test: logs into the real running Laravel
process (Sanctum SPA cookie auth), triggers a real agent run, and polls it
to confirm Laravel's queue worker -> AiEngineClient -> FastAPI ai-engine ->
signed event callback -> Laravel round trip actually works end-to-end over
real HTTP with real processes. Not part of either service's pytest/PHPUnit
suite -- needs `php artisan serve`, `php artisan queue:work`, and
`uvicorn app.main:app` already running (see the shell commands used to
launch this check).
"""
import sys
import time

import httpx

BASE = "http://127.0.0.1:8000"


def main() -> int:
    # Sanctum's EnsureFrontendRequestsAreStateful only starts a session for
    # requests whose Referer/Origin host is in SANCTUM_STATEFUL_DOMAINS --
    # a bare httpx client sends neither, so every request below must carry
    # one explicitly (this is exactly what a real browser SPA does for free).
    client = httpx.Client(base_url=BASE, timeout=15, headers={"Referer": "http://localhost:5173/"})
    client.get("/sanctum/csrf-cookie")
    xsrf = client.cookies.get("XSRF-TOKEN")
    if not xsrf:
        print("FAIL: no XSRF-TOKEN cookie set")
        return 1
    import urllib.parse

    headers = {"X-XSRF-TOKEN": urllib.parse.unquote(xsrf), "Accept": "application/json"}

    r = client.post("/api/v1/auth/login", json={"email": "demo@aura.trading", "password": "password"}, headers=headers)
    print(f"login -> {r.status_code}: {r.text[:200]}")
    if r.status_code >= 400:
        return 1

    # Laravel regenerates the session (and its CSRF token) on login to guard
    # against session fixation, so the XSRF-TOKEN cookie captured before
    # login is now stale -- re-read it from the jar before the next request.
    xsrf = client.cookies.get("XSRF-TOKEN")
    headers["X-XSRF-TOKEN"] = urllib.parse.unquote(xsrf)

    r = client.post("/api/v1/agent-runs", json={"symbols": ["AAPL"], "mode": "demo"}, headers=headers)
    print(f"create run -> {r.status_code}: {r.text[:300]}")
    if r.status_code >= 400:
        return 1
    run_id = r.json()["data"]["id"]

    for attempt in range(10):
        time.sleep(1)
        r = client.get(f"/api/v1/agent-runs/{run_id}", headers=headers)
        data = r.json().get("data", {})
        status = data.get("status")
        print(f"poll {attempt} -> status={status}")
        if status not in ("pending", "running"):
            break

    print("final run payload:", r.text[:1500])
    return 0 if status not in ("pending",) else 1


if __name__ == "__main__":
    sys.exit(main())
