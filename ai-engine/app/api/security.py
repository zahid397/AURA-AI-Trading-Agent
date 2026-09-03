"""Verifies the HMAC signature Laravel attaches to internal requests.

Mirrors `LaravelEventClient._sign` in `app/services/laravel_events.py` and
Laravel's own `App\Http\Middleware\VerifyInternalSignature` byte for byte:
`X-Aura-Signature` is the *raw lowercase hex digest* of
`hmac_sha256("{timestamp}.{raw body}", secret)` -- no `sha256=` prefix. Both
services must agree on this exactly, since each one is also the other's
client (see `app/services/laravel_events.py`).
"""
from __future__ import annotations

import hashlib
import hmac
import time

from fastapi import HTTPException, Request

MAX_TIMESTAMP_SKEW_SECONDS = 300


async def verify_internal_request(request: Request) -> bytes:
    from app.core.config import get_settings

    settings = get_settings()
    body = await request.body()
    timestamp = request.headers.get("X-Aura-Timestamp")
    signature = request.headers.get("X-Aura-Signature")
    if not timestamp or not signature:
        raise HTTPException(status_code=401, detail={"error_code": "missing_signature_headers"})

    try:
        ts = int(timestamp)
    except ValueError:
        raise HTTPException(status_code=401, detail={"error_code": "invalid_timestamp"})

    if abs(time.time() - ts) > MAX_TIMESTAMP_SKEW_SECONDS:
        raise HTTPException(status_code=401, detail={"error_code": "timestamp_skew_exceeded"})

    message = timestamp.encode() + b"." + body
    expected = hmac.new(settings.internal_signing_secret.encode(), message, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise HTTPException(status_code=401, detail={"error_code": "invalid_signature"})

    return body
