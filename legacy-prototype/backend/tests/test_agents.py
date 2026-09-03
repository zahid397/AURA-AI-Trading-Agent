"""
test_agents.py

Real unit tests using Python's built-in `unittest` module. pytest is not
pre-installed in this sandbox and could not be pip-installed (see
IMPLEMENTATION_STATUS.md), so this uses stdlib unittest instead -- same
assertions, same coverage intent, no external dependency.

Run with:  python3 -m unittest tests.test_agents -v
"""

import unittest
import math
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from market_math import sma, rsi, black_scholes, synthetic_price_series
from agents import risk_manager, market_analyst, options_strategy, run_pipeline, learning
from mock_alpaca import MockAlpacaAdapter, assert_paper_only, LiveTradingBlockedError


class TestMarketMath(unittest.TestCase):
    def test_sma_basic(self):
        self.assertEqual(sma([1, 2, 3, 4, 5], 5), 3.0)
        self.assertIsNone(sma([1, 2], 5))  # not enough data

    def test_rsi_bounds(self):
        # all gains -> RSI should be 100 (no losses to divide by)
        rising = [100 + i for i in range(20)]
        self.assertEqual(rsi(rising, 14), 100.0)

    def test_black_scholes_put_call_parity(self):
        spot, strike, dte, vol, rate = 200.0, 200.0, 30, 0.22, 0.045
        c = black_scholes(spot, strike, dte, vol, rate, "call")
        p = black_scholes(spot, strike, dte, vol, rate, "put")
        lhs = c.price - p.price
        rhs = spot - strike * math.exp(-rate * dte / 365)
        self.assertAlmostEqual(lhs, rhs, delta=0.05)

    def test_black_scholes_monotonic_in_vol(self):
        spot, strike, dte = 200.0, 210.0, 30
        low = black_scholes(spot, strike, dte, 0.10, option_type="call")
        high = black_scholes(spot, strike, dte, 0.50, option_type="call")
        self.assertGreater(high.price, low.price)

    def test_synthetic_series_deterministic(self):
        s1 = synthetic_price_series("SPY", 30)
        s2 = synthetic_price_series("SPY", 30)
        self.assertEqual(s1, s2)  # same symbol -> same series, every call
        self.assertTrue(all(p > 0 for p in s1))  # price can never go non-positive


class TestRiskManager(unittest.TestCase):
    def setUp(self):
        self.account = {"equity": 100000.0, "daily_pl": 0.0}

    def test_rejects_when_killed(self):
        strat = {"confidence": 90, "max_loss": 100}
        r = risk_manager(strat, self.account, open_exposure=0, killed=True)
        self.assertFalse(r["approved"])
        self.assertIn("kill switch", r["reasons"][0].lower())

    def test_rejects_low_confidence(self):
        strat = {"confidence": 40, "max_loss": 100}
        r = risk_manager(strat, self.account, open_exposure=0, killed=False)
        self.assertFalse(r["approved"])

    def test_rejects_over_max_loss_cap(self):
        strat = {"confidence": 90, "max_loss": 5000}  # > 2% of 100k
        r = risk_manager(strat, self.account, open_exposure=0, killed=False)
        self.assertFalse(r["approved"])

    def test_rejects_over_exposure_cap(self):
        strat = {"confidence": 90, "max_loss": 500}
        r = risk_manager(strat, self.account, open_exposure=26000, killed=False)
        self.assertFalse(r["approved"])

    def test_approves_clean_trade(self):
        strat = {"confidence": 90, "max_loss": 500}
        r = risk_manager(strat, self.account, open_exposure=0, killed=False)
        self.assertTrue(r["approved"])
        self.assertEqual(r["position_size"], 1)


class TestPipeline(unittest.TestCase):
    def test_full_pipeline_runs_without_error(self):
        adapter = MockAlpacaAdapter()
        for symbol in ["SPY", "NVDA", "TSLA", "AAPL", "MSFT"]:
            result = run_pipeline(symbol, adapter, open_exposure=0, killed=False, trade_history=[])
            self.assertEqual(result["symbol"], symbol)
            self.assertIn(result["strategy"]["strategy_key"],
                          {"bull_call_spread", "bear_put_spread", "iron_condor",
                           "covered_call", "cash_secured_put"})
            self.assertIsInstance(result["risk"]["approved"], bool)

    def test_kill_switch_blocks_execution(self):
        adapter = MockAlpacaAdapter()
        result = run_pipeline("SPY", adapter, open_exposure=0, killed=True, trade_history=[])
        self.assertFalse(result["execution"]["submitted"])

    def test_learning_with_no_history(self):
        out = learning([])
        self.assertEqual(out["sample_size"], 0)

    def test_learning_computes_real_stats(self):
        history = [
            {"status": "CLOSED", "pnl": 100}, {"status": "CLOSED", "pnl": -50},
            {"status": "CLOSED", "pnl": 200}, {"status": "OPEN", "pnl": 0},
        ]
        out = learning(history)
        self.assertEqual(out["sample_size"], 3)
        self.assertAlmostEqual(out["win_rate"], 66.67, places=1)


class TestMockAlpacaAdapter(unittest.TestCase):
    def test_paper_only_guard_blocks_live(self):
        with self.assertRaises(LiveTradingBlockedError):
            assert_paper_only("live")
        assert_paper_only("paper")  # should not raise
        assert_paper_only("demo")   # should not raise

    def test_order_idempotency(self):
        adapter = MockAlpacaAdapter()
        legs = [{"action": "BUY", "type": "CALL", "strike": 100, "price": 2.0}]
        o1 = adapter.submit_option_order(symbol="SPY", legs=legs, qty=1, limit_price=2.0,
                                          idempotency_key="key-1")
        o2 = adapter.submit_option_order(symbol="SPY", legs=legs, qty=1, limit_price=2.0,
                                          idempotency_key="key-1")
        self.assertEqual(o1["order_id"], o2["order_id"])
        self.assertTrue(o2["idempotent_replay"])

    def test_distinct_keys_produce_distinct_orders(self):
        adapter = MockAlpacaAdapter()
        legs = [{"action": "BUY", "type": "CALL", "strike": 100, "price": 2.0}]
        o1 = adapter.submit_option_order(symbol="SPY", legs=legs, qty=1, limit_price=2.0,
                                          idempotency_key="a")
        o2 = adapter.submit_option_order(symbol="SPY", legs=legs, qty=1, limit_price=2.0,
                                          idempotency_key="b")
        self.assertNotEqual(o1["order_id"], o2["order_id"])


if __name__ == "__main__":
    unittest.main()
