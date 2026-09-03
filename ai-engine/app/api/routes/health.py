"""Unauthenticated liveness/readiness -- no internal signature required here,
matching Laravel's own `/health` convention so both services can be probed
the same way by Docker healthchecks."""
from __future__ import annotations

from fastapi import APIRouter, Request

from app.models.api import HealthResponse, ReadyResponse

router = APIRouter(tags=["health"])


@router.get("/health", response_model=HealthResponse)
async def health() -> HealthResponse:
    return HealthResponse(status="ok")


@router.get("/ready", response_model=ReadyResponse)
async def ready(request: Request) -> ReadyResponse:
    checks = {
        "checkpointer_initialized": getattr(request.app.state, "run_manager", None) is not None,
        "settings_loaded": getattr(request.app.state, "settings", None) is not None,
    }
    return ReadyResponse(ready=all(checks.values()), checks=checks)
