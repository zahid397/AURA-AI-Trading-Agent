# AURA AI Trading Agent — Hackathon Writeup

## Problem

Retail options trading tools either hand you a raw options chain and expect
you to do the math yourself, or they hand you an opaque "AI signal" with no
way to check its work. Neither is trustworthy enough to automate, and
automating an untrustworthy signal is how accounts blow up.

## Solution

AURA turns market evidence into an explainable, risk-gated options strategy,
and only then — after a deterministic risk engine an LLM cannot override —
either asks a human to approve it or (if explicitly configured for
`paper_autonomous` mode) submits it to Alpaca's paper trading API itself.
Every number shown is either a real fact (a quote, a Greek, an account
balance) with a timestamp, or clearly labeled as a model estimate.

## Why multi-agent

A single LLM call asked to "find me a good trade" conflates four different
kinds of judgment: reading the market, picking a structure, deciding if the
risk is acceptable, and actually executing safely. Splitting these into five
agents (Market Analyst, Options Strategy, Risk Guardian, Execution, Learning)
means each one has a narrow, auditable job, and — critically — lets the risk
gate be *deterministic code*, not another LLM call the first LLM's mistakes
could talk its way past.

## Architecture

Vue 3 SPA -> Laravel 13 (auth, domain data, system of record) -> FastAPI +
LangGraph (orchestration, agents, Alpaca integration) -> Alpaca paper API /
MCP server. See `docs/architecture.md` for the full diagram and trust
boundaries, and `docs/workflow.md` for the LangGraph state machine itself.

## Alpaca Trading API / MCP / CLI usage

- **Trading API**: direct `alpaca-py==0.44.0` client, paper-only by a
  hard-coded literal (`TradingClient(..., paper=True)`), used for account,
  clock, positions, option chains, and multi-leg (`order_class="mleg"`)
  order submission.
- **MCP**: the official `alpaca-mcp-server` PyPI package, launched via
  `uvx alpaca-mcp-server`; tools are discovered at connection time via MCP's
  `list_tools` and mapped to internal capability names — never assumed.
- **CLI**: the official `alpaca` CLI (`github.com/alpacahq/cli`), wrapped in
  a safe, allowlisted, shell-free subprocess call for diagnostics only.

## Risk design

`app/risk/engine.py` is a plain function: given already-computed inputs, it
returns a fixed, always-fully-populated list of rule results (paper
verification, options capability, market session, quote freshness,
confidence floor, liquidity, bounded max loss, per-trade/position/total/
per-symbol exposure caps, open-position count, daily loss limit, rolling
drawdown, expiration window, kill switch, duplicate-proposal detection). Any
CRITICAL failure rejects the trade outright; the LLM agents never see a code
path that can edit a limit or skip a rule.

## Explainability

Every thesis carries `evidence` items (label, value, timestamp, source) and
`invalidation_conditions`. Every strategy candidate carries its exact legs,
payoff math, and a decomposed score (confidence / liquidity / return-on-risk,
with the weights shown). Every risk verdict carries the full rule list, not
just approve/reject. None of this is chain-of-thought — it's structured data
the LLM is asked to narrate, never originate.

## Options strategy scope

Five strategies, chosen by a transparent regime-to-strategy policy: Bull
Call Spread, Bear Put Spread, Iron Condor, Covered Call, Cash-Secured Put.
No equity-only order path exists in the execution agent.

## Challenges

- **Environment constraints mid-build**: this repo's history includes an
  earlier prototype built in a sandbox with no PHP, no Docker, and no
  package-registry access at all (see `legacy-prototype/`). This build's own
  session had Docker installed but its backend service couldn't be started
  without admin rights, and PHP wasn't on `PATH` — worked around with a
  portable, no-admin PHP + Composer install rather than skipping the Laravel
  layer entirely (see `docs/IMPLEMENTATION_STATUS.md`).
- **LangGraph interrupt semantics**: getting a paused run's status to
  actually reflect "awaiting approval" required realizing that code before
  an `interrupt()` call in the same node never commits — fixed by splitting
  the status update into its own node (documented as bug #2 in
  `docs/IMPLEMENTATION_STATUS.md`).
- **No-look-ahead in the backtester**: an early version's synthetic price
  generator was accidentally seeded by both symbol *and* window length,
  so widening a backtest window silently changed earlier days' prices —
  caught by a test that specifically checks this, not by inspection.

## Results

60/60 backend (`ai-engine`) tests passing as of this writeup, including a
full HTTP round-trip through a real running FastAPI process covering signed
auth, a complete autonomous run to a filled demo order, and a complete
approval-required interrupt/resume cycle. See
`docs/IMPLEMENTATION_STATUS.md` for the Laravel/frontend equivalents and
what remains open.

## Limitations

- No live Alpaca paper account was connected during this build session — all
  Alpaca-shaped verification is against the `demo` adapter and the installed
  SDK's schemas, not a live call.
- The backtest engine only covers two of five strategies and uses synthetic,
  not historical, price data — labeled as such in its own output.
- Docker Compose is written but not build-tested in this session.

## Next steps

- Connect a real fresh Alpaca paper account and re-run the full demo script
  against it.
- Extend the backtest engine to iron condor / covered call / cash-secured
  put.
- Wire Laravel Reverb for true push updates (current build documents a
  polling fallback where broadcasting wasn't finished in time).
