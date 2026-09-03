"""FastAPI app entry point.

`AsyncSqliteSaver` (LangGraph's durable checkpointer) is opened once at
startup and kept alive for the process lifetime via the lifespan context
manager -- this is what lets an `approval_required` run survive a human
taking minutes or hours to respond, and even a process restart in between
(as long as the sqlite file persists, which it does on the shared volume in
`compose.yml`).
"""
from __future__ import annotations

from contextlib import asynccontextmanager

from fastapi import FastAPI
from langgraph.checkpoint.sqlite.aio import AsyncSqliteSaver

from app.api.routes import backtests, capabilities, health, runs
from app.core.config import get_settings
from app.services.run_manager import RunManager


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()  # raises ConfigurationError -> app fails to start on a bad/live config
    app.state.settings = settings
    async with AsyncSqliteSaver.from_conn_string(settings.langgraph_checkpoint_path) as checkpointer:
        app.state.run_manager = RunManager(settings, checkpointer)
        yield


app = FastAPI(
    title="AURA AI Engine",
    description="FastAPI + LangGraph multi-agent options trading engine (paper trading only).",
    version="0.1.0",
    lifespan=lifespan,
)

app.include_router(health.router)
app.include_router(runs.router, prefix="/internal/v1")
app.include_router(capabilities.router, prefix="/internal/v1")
app.include_router(backtests.router, prefix="/internal/v1")
