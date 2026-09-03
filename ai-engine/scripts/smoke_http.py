"""One-off manual smoke test: drives the real running FastAPI process over
HTTP with correctly-signed requests, the same way Laravel would. Not part of
the pytest suite (it needs `uvicorn app.main:app` already running) -- this
is for verifying the actual wire format end-to-end, which unit tests that
call node functions directly don't exercise.
"""
import hashlib
import hmac
import json
import sys
import time
from uuid import uuid4

import httpx

BASE = "http://127.0.0.1:8001/internal/v1"
SECRET = "local-dev-internal-signing-secret-change-me"


def signed_headers(body: bytes) -> dict:
    ts = str(int(time.time()))
    sig = hmac.new(SECRET.encode(), ts.encode() + b"." + body, hashlib.sha256).hexdigest()
    return {"Content-Type": "application/json", "X-Aura-Timestamp": ts, "X-Aura-Signature": sig}


def post(path, payload):
    body = json.dumps(payload, separators=(",", ":"), sort_keys=True).encode()
    r = httpx.post(f"{BASE}{path}", content=body, headers=signed_headers(body), timeout=30)
    print(f"POST {path} -> {r.status_code}: {r.text[:500]}")
    return r


def get(path):
    r = httpx.get(f"{BASE}{path}", headers=signed_headers(b""), timeout=30)
    print(f"GET {path} -> {r.status_code}: {r.text[:800]}")
    return r


if __name__ == "__main__":
    run_id = str(uuid4())
    post("/runs", {
        "run_id": run_id, "user_id": str(uuid4()), "mode": "demo", "trigger": "manual",
        "symbols": ["SPY"], "risk_policy_version": 1,
    })
    time.sleep(2)
    r = get(f"/runs/{run_id}")
    caps = get("/capabilities")
    sys.exit(0 if r.status_code == 200 else 1)
