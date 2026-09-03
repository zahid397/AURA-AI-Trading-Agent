"""
data_store.py

Real SQLite persistence (stdlib sqlite3, no server process needed) for demo
positions, trade history, and the agent run/audit log. This is genuine
persistent storage -- restart the Flask process and the data is still there in
aura_demo.db -- just not the PostgreSQL the master prompt specifies, since no
Postgres server is running in this sandbox for the demo to connect to. Swapping
this module's `sqlite3` calls for a `psycopg`/SQLAlchemy Postgres connection
would be the natural upgrade path (see docs/IMPLEMENTATION_STATUS.md).
"""

from __future__ import annotations
import sqlite3
import time
import os
import json

DB_PATH = os.path.join(os.path.dirname(__file__), "aura_demo.db")

SCHEMA = """
CREATE TABLE IF NOT EXISTS positions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol TEXT NOT NULL,
    strategy_label TEXT NOT NULL,
    legs_json TEXT NOT NULL,
    opened_at REAL NOT NULL,
    max_loss REAL NOT NULL,
    max_gain REAL NOT NULL,
    unrealized_pl REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'OPEN'
);

CREATE TABLE IF NOT EXISTS trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol TEXT NOT NULL,
    strategy_label TEXT NOT NULL,
    opened_at REAL NOT NULL,
    closed_at REAL,
    pnl REAL,
    status TEXT NOT NULL DEFAULT 'OPEN'
);

CREATE TABLE IF NOT EXISTS agent_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id TEXT NOT NULL,
    symbol TEXT NOT NULL,
    created_at REAL NOT NULL,
    result_json TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
"""

SEED_POSITIONS = [
    ("SPY", "Bull Call Spread",
     [{"action": "BUY", "type": "CALL", "strike": 525, "price": 2.45},
      {"action": "SELL", "type": "CALL", "strike": 535, "price": 0.85}],
     550.0, 1450.0, 82.34),
    ("NVDA", "Iron Condor",
     [{"action": "SELL", "type": "PUT", "strike": 900, "price": 3.10},
      {"action": "BUY", "type": "PUT", "strike": 850, "price": 1.40},
      {"action": "SELL", "type": "CALL", "strike": 1200, "price": 2.80},
      {"action": "BUY", "type": "CALL", "strike": 1250, "price": 1.10}],
     640.0, 340.0, 56.21),
    ("TSLA", "Cash Secured Put",
     [{"action": "SELL", "type": "PUT", "strike": 180, "price": 3.65}],
     16400.0, 365.0, 43.79),
]


def _connect():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def init_db(reset: bool = False):
    if reset and os.path.exists(DB_PATH):
        os.remove(DB_PATH)
    conn = _connect()
    conn.executescript(SCHEMA)
    cur = conn.execute("SELECT COUNT(*) AS c FROM positions")
    if cur.fetchone()["c"] == 0:
        for symbol, label, legs, max_loss, max_gain, upl in SEED_POSITIONS:
            conn.execute(
                "INSERT INTO positions (symbol, strategy_label, legs_json, opened_at, "
                "max_loss, max_gain, unrealized_pl, status) VALUES (?,?,?,?,?,?,?,?)",
                (symbol, label, json.dumps(legs), time.time() - 86400, max_loss, max_gain, upl, "OPEN"),
            )
        # a few closed trades so the Learning agent has something real to compute over
        closed_seed = [
            ("AAPL", "Covered Call", -3 * 86400, -1 * 86400, 45.21),
            ("MSFT", "Bull Put Spread", -5 * 86400, -2 * 86400, 32.18),
            ("AMD", "Iron Condor", -10 * 86400, -6 * 86400, -28.40),
        ]
        for symbol, label, opened_off, closed_off, pnl in closed_seed:
            conn.execute(
                "INSERT INTO trades (symbol, strategy_label, opened_at, closed_at, pnl, status) "
                "VALUES (?,?,?,?,?,?)",
                (symbol, label, time.time() + opened_off, time.time() + closed_off, pnl, "CLOSED"),
            )
        conn.execute("INSERT OR IGNORE INTO settings (key, value) VALUES ('killed', 'false')")
        conn.commit()
    conn.close()


def get_positions() -> list[dict]:
    conn = _connect()
    rows = conn.execute("SELECT * FROM positions WHERE status='OPEN' ORDER BY opened_at DESC").fetchall()
    conn.close()
    return [dict(r) | {"legs": json.loads(r["legs_json"])} for r in rows]


def get_open_exposure() -> float:
    conn = _connect()
    row = conn.execute("SELECT COALESCE(SUM(max_loss),0) AS s FROM positions WHERE status='OPEN'").fetchone()
    conn.close()
    return float(row["s"])


def get_trade_history(limit: int = 50) -> list[dict]:
    conn = _connect()
    rows = conn.execute("SELECT * FROM trades ORDER BY opened_at DESC LIMIT ?", (limit,)).fetchall()
    conn.close()
    return [dict(r) for r in rows]


def record_run(run_id: str, symbol: str, result: dict):
    conn = _connect()
    conn.execute(
        "INSERT INTO agent_runs (run_id, symbol, created_at, result_json) VALUES (?,?,?,?)",
        (run_id, symbol, time.time(), json.dumps(result, default=str)),
    )
    if result["execution"]["submitted"]:
        strat = result["strategy"]
        conn.execute(
            "INSERT INTO positions (symbol, strategy_label, legs_json, opened_at, max_loss, "
            "max_gain, unrealized_pl, status) VALUES (?,?,?,?,?,?,?,?)",
            (symbol, strat["strategy_label"], json.dumps(strat["legs"]), time.time(),
             strat["max_loss"], strat["max_gain"], 0.0, "OPEN"),
        )
    conn.commit()
    conn.close()


def get_recent_runs(limit: int = 20) -> list[dict]:
    conn = _connect()
    rows = conn.execute("SELECT * FROM agent_runs ORDER BY created_at DESC LIMIT ?", (limit,)).fetchall()
    conn.close()
    out = []
    for r in rows:
        d = dict(r)
        d["result"] = json.loads(d.pop("result_json"))
        out.append(d)
    return out


def get_setting(key: str, default=None):
    conn = _connect()
    row = conn.execute("SELECT value FROM settings WHERE key=?", (key,)).fetchone()
    conn.close()
    return row["value"] if row else default


def set_setting(key: str, value: str):
    conn = _connect()
    conn.execute("INSERT INTO settings (key, value) VALUES (?, ?) "
                 "ON CONFLICT(key) DO UPDATE SET value=excluded.value", (key, value))
    conn.commit()
    conn.close()
