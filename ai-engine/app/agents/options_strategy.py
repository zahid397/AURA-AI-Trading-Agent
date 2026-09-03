"""Options Strategy Agent.

Thin orchestration layer: fetches a real option chain through the injected
`AlpacaGateway`, then hands it to `app/strategies/selector.py` for the actual
regime-to-strategy policy and deterministic payoff math. This module never
invents strikes, premiums, or Greeks -- everything comes from the chain the
gateway returns (real Alpaca data, or the demo adapter's Black-Scholes-priced
synthetic chain, both going through the same `OptionContract` shape).
"""
from __future__ import annotations

from decimal import Decimal

from app.alpaca.gateway import AlpacaGateway
from app.strategies import selector
from app.workflows.state import (
    MarketThesis,
    ScanFilters,
    StrategyCandidate,
    TechnicalFeatures,
)


async def propose(
    symbol: str,
    spot: Decimal,
    thesis: MarketThesis,
    features: TechnicalFeatures,
    gateway: AlpacaGateway,
    filters: ScanFilters,
    share_cost_basis: Decimal | None = None,
) -> StrategyCandidate | None:
    chain = await gateway.get_option_chain(symbol, filters.min_dte, filters.max_dte)
    if not chain:
        return None
    return selector.build_candidate(
        symbol=symbol,
        spot=spot,
        thesis=thesis,
        features=features,
        chain=chain,
        filters=filters,
        share_cost_basis=share_cost_basis,
    )


def rank(candidates: list[StrategyCandidate]) -> list[StrategyCandidate]:
    return selector.rank_candidates(candidates)
