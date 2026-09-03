from __future__ import annotations

from fastapi import APIRouter, Depends, Request

from app.alpaca.capabilities import build_capability_snapshot
from app.api.security import verify_internal_request

router = APIRouter(tags=["capabilities"])


@router.get("/capabilities", dependencies=[Depends(verify_internal_request)])
async def get_capabilities(request: Request) -> dict:
    settings = request.app.state.settings
    snapshot = await build_capability_snapshot(settings)
    return snapshot.model_dump(mode="json")
