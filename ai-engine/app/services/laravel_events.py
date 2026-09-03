"""Signs and posts agent-run events to Laravel.

Implements the FastAPI -> Laravel half of the internal contract in
docs/architecture.md: HMAC-SHA256 over the exact raw JSON body, plus a
timestamp header Laravel checks for a 5-minute skew window. The signature
header is the *raw lowercase hex digest* -- no `sha256=` prefix -- matching
Laravel's `App\Http\Middleware\VerifyInternalSignature` exactly, since
Laravel signs its own outgoing calls to this service the same way (see
`app/api/security.py`). Laravel is the one that de-duplicates by `event_id`
(this client does not retry blindly on a timeout -- a caller that wants
at-least-once delivery must retry the whole `send_event` call, which is safe
because the event_id makes it idempotent on Laravel's side).
"""
from __future__ import annotations

import hashlib
import hmac
import json
import time
from typing import Any

import httpx


class LaravelEventClient:
    def __init__(self, base_url: str, signing_secret: str, timeout_seconds: float = 10.0):
        self._base_url = base_url.rstrip("/")
        self._signing_secret = signing_secret.encode()
        self._timeout = timeout_seconds

    def _sign(self, body: bytes, timestamp: str) -> str:
        message = timestamp.encode() + b"." + body
        return hmac.new(self._signing_secret, message, hashlib.sha256).hexdigest()

    async def send_event(self, event: dict[str, Any]) -> httpx.Response:
        body = json.dumps(event, separators=(",", ":"), sort_keys=True).encode()
        timestamp = str(int(time.time()))
        signature = self._sign(body, timestamp)
        async with httpx.AsyncClient(timeout=self._timeout) as client:
            return await client.post(
                f"{self._base_url}/events",
                content=body,
                headers={
                    "Content-Type": "application/json",
                    "X-Aura-Timestamp": timestamp,
                    "X-Aura-Signature": signature,
                    "X-Aura-Service": "fastapi",
                },
            )

    async def send_events(self, events: list[dict[str, Any]]) -> list[httpx.Response]:
        """Sends events one at a time, in order -- Laravel processes each in
        its own transaction, so a mid-batch failure doesn't roll back
        earlier, already-accepted events."""
        return [await self.send_event(e) for e in events]


def audit_event_to_wire(event, run_id: str, trace_id: str) -> dict[str, Any]:
    """Converts an `app.workflows.state.AuditEvent` into the wire shape
    Laravel's `POST /api/internal/v1/events` expects."""
    return {
        "event_id": str(event.event_id),
        "run_id": run_id,
        "trace_id": trace_id,
        "type": event.type,
        "payload": event.payload,
        "occurred_at": event.occurred_at.isoformat(),
    }
