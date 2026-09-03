"""
app.py

Flask backend (substituting for the Laravel + FastAPI two-service split the
master prompt specifies -- see docs/IMPLEMENTATION_STATUS.md for why: no PHP
runtime exists in this sandbox and packagist.org is not reachable, so a Laravel
service cannot be built or run here at all; FastAPI itself was also not
pip-installable in this sandbox at build time, so Flask -- already present in
the base image -- was used instead. This single Flask service now does what
Laravel + FastAPI would have split between them: it serves the API, owns the
SQLite data, and runs the agent pipeline in-process.).

Every route below is real and actually executes the logic in agents.py against
data_store.py / mock_alpaca.py -- nothing here just returns a fixed literal
dict pretending to be a live computation, with the obvious exception of the
seeded demo positions/trades in data_store.py, which exist to make the first
page load look like a system with history rather than an empty database.
"""

from __future__ import annotations
import os
import time
from flask import Flask, jsonify, request, send_from_directory

import data_store as db
from agents import run_pipeline
from mock_alpaca import MockAlpacaAdapter, assert_paper_only

TRADING_ENV = os.environ.get("TRADING_ENV", "paper")
assert_paper_only(TRADING_ENV)  # refuses to start at all if misconfigured to "live"

FRONTEND_DIR = os.path.join(os.path.dirname(__file__), "..", "frontend")

app = Flask(__name__, static_folder=None)
adapter = MockAlpacaAdapter()
db.init_db()

WATCHLIST = ["SPY", "NVDA", "TSLA", "AAPL", "MSFT"]


# ---------------------------------------------------------------------------
# Static frontend (same-origin, so no CORS layer is needed)
# ---------------------------------------------------------------------------

@app.route("/")
def index():
    return send_from_directory(FRONTEND_DIR, "index.html")


@app.route("/<path:path>")
def static_files(path):
    return send_from_directory(FRONTEND_DIR, path)


# ---------------------------------------------------------------------------
# API
# ---------------------------------------------------------------------------

@app.route("/api/health")
def health():
    return jsonify({"status": "ok", "trading_env": TRADING_ENV,
                     "adapter_mode": adapter.mode, "time": time.time()})


@app.route("/api/account")
def account():
    acc = adapter.get_account()
    positions = db.get_positions()
    unrealized = round(sum(p["unrealized_pl"] for p in positions), 2)
    acc["unrealized_pl"] = unrealized
    acc["daily_pl"] = round(unrealized, 2)  # simplified: demo has no separate closed-today P&L
    acc["risk_level"] = "LOW" if abs(unrealized) < acc["equity"] * 0.02 else "ELEVATED"
    acc["killed"] = db.get_setting("killed", "false") == "true"
    return jsonify(acc)


@app.route("/api/positions")
def positions():
    return jsonify(db.get_positions())


@app.route("/api/trades")
def trades():
    return jsonify(db.get_trade_history())


@app.route("/api/agents")
def agents_status():
    killed = db.get_setting("killed", "false") == "true"
    names = [
        ("Market Analyst Agent", "Computes SMA/RSI/volatility from the latest synthetic price series."),
        ("Options Strategy Agent", "Selects a strategy and prices legs with Black-Scholes."),
        ("Risk Manager Agent", "Deterministic hard gates: confidence, max loss, exposure, daily loss."),
        ("Execution Agent", "Submits approved orders to the mock paper adapter (idempotent)."),
        ("Learning Agent", "Computes win rate / profit factor from closed trades."),
    ]
    status = "PAUSED" if killed else "ACTIVE"
    return jsonify([{"name": n, "description": d, "status": status} for n, d in names])


@app.route("/api/decisions/latest")
def latest_decision():
    runs = db.get_recent_runs(limit=1)
    if not runs:
        return jsonify(None)
    return jsonify(runs[0])


@app.route("/api/decisions/history")
def decision_history():
    return jsonify(db.get_recent_runs(limit=20))


@app.route("/api/run", methods=["POST"])
def trigger_run():
    body = request.get_json(silent=True) or {}
    symbol = body.get("symbol") or WATCHLIST[int(time.time()) % len(WATCHLIST)]
    killed = db.get_setting("killed", "false") == "true"
    open_exposure = db.get_open_exposure()
    trade_history = db.get_trade_history()

    result = run_pipeline(symbol, adapter, open_exposure, killed, trade_history)
    db.record_run(result["run_id"], symbol, result)
    return jsonify(result)


@app.route("/api/kill-switch", methods=["POST"])
def kill_switch():
    body = request.get_json(silent=True) or {}
    new_state = bool(body.get("killed", True))
    db.set_setting("killed", "true" if new_state else "false")
    return jsonify({"killed": new_state})


@app.route("/api/performance")
def performance():
    trades_ = db.get_trade_history()
    closed = [t for t in trades_ if t["status"] == "CLOSED"]
    positions_ = db.get_positions()
    total_realized = round(sum(t["pnl"] for t in closed), 2)
    total_unrealized = round(sum(p["unrealized_pl"] for p in positions_), 2)
    wins = [t for t in closed if t["pnl"] > 0]
    win_rate = round(100 * len(wins) / len(closed), 2) if closed else None
    gross_win = sum(t["pnl"] for t in wins) or 0.0
    gross_loss = abs(sum(t["pnl"] for t in closed if t["pnl"] <= 0)) or 0.0001
    profit_factor = round(gross_win / gross_loss, 2) if closed else None
    return jsonify({
        "total_realized_pl": total_realized,
        "total_unrealized_pl": total_unrealized,
        "win_rate": win_rate,
        "profit_factor": profit_factor,
        "closed_trade_count": len(closed),
        "open_position_count": len(positions_),
    })


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5001))
    app.run(host="0.0.0.0", port=port, debug=False)
