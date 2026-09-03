FROM python:3.12-slim

WORKDIR /app
# `pip install .` needs the actual package present to build from -- pyproject.toml
# alone isn't enough (setuptools has nothing to discover/ship). Both are copied
# together before installing rather than caching pyproject.toml as its own layer,
# trading a little rebuild speed for a build that actually works.
COPY ai-engine/pyproject.toml ./
COPY ai-engine/app ./app
# --timeout/--retries: alpaca-py pulls in pandas/numpy (large wheels) --
# PyPI's CDN occasionally stalls mid-download, which a bare `pip install`'s
# default timeout doesn't tolerate well inside a container network path.
RUN pip install --no-cache-dir --timeout=100 --retries=5 . \
    # `uv`/`uvx` are required at runtime to spawn the official
    # `alpaca-mcp-server` package over stdio (see app/alpaca/mcp_client.py) --
    # without them the `alpaca_mcp` channel reports itself unavailable and
    # the gateway falls back to the direct `alpaca_api` adapter.
    && pip install --no-cache-dir --timeout=100 --retries=5 uv

ENV TRADING_ENV=demo \
    ALLOW_LIVE_TRADING=false \
    LANGGRAPH_CHECKPOINT_PATH=/data/aura_langgraph_checkpoints.sqlite

VOLUME ["/data"]
EXPOSE 8001
HEALTHCHECK --interval=15s --timeout=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8001/health')"

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
