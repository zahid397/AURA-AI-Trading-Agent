"""Market Analyst Agent.

Confidence is a documented, transparent heuristic (RSI distance from
neutral + trend/MACD agreement + data completeness), never a calibrated
model output -- this mirrors the explicit warning in
legacy-prototype/docs/IMPLEMENTATION_STATUS.md and the master spec's
requirement that confidence not be presented as a trained model's output.
The LLM (`llm.generate`) is used only to narrate the evidence already
computed below into prose; it never sets `direction` or `confidence` itself.
"""
from __future__ import annotations

from datetime import UTC, datetime

from app.indicators import technical
from app.llm.provider import LLMProvider
from app.workflows.state import (
    Direction,
    EvidenceItem,
    MarketDataSnapshot,
    MarketThesis,
    TechnicalFeatures,
)


def compute_features(symbol: str, snapshot: MarketDataSnapshot) -> TechnicalFeatures:
    closes = [float(b.close) for b in snapshot.bars]
    highs = [float(b.high) for b in snapshot.bars]
    lows = [float(b.low) for b in snapshot.bars]
    volumes = [b.volume for b in snapshot.bars]

    warnings: list[str] = []
    if len(closes) < 35:
        warnings.append("Fewer than 35 bars of history -- MACD/trend signals may be unreliable.")

    ema_fast = technical.ema(closes, 12) or (closes[-1] if closes else 0.0)
    ema_slow = technical.ema(closes, 26) or (closes[-1] if closes else 0.0)
    trend = technical.ema_trend(closes)
    rsi_val = technical.rsi(closes) or 50.0
    macd_pair = technical.macd(closes)
    macd_line, macd_signal = macd_pair if macd_pair else (0.0, 0.0)
    atr_val = technical.atr(highs, lows, closes) or 0.0
    vol_val = technical.realized_volatility(closes) or 0.0
    rel_vol = technical.relative_volume(volumes) or 1.0

    spread_pct = None
    if snapshot.quote_bid is not None and snapshot.quote_ask is not None and snapshot.quote_ask > 0:
        spread_pct = (snapshot.quote_ask - snapshot.quote_bid) / snapshot.quote_ask
    else:
        warnings.append("No live bid/ask on the underlying -- spread-based liquidity checks skipped.")

    from decimal import Decimal

    return TechnicalFeatures(
        symbol=symbol,
        computed_at=datetime.now(UTC),
        ema_fast=Decimal(str(round(ema_fast, 4))),
        ema_slow=Decimal(str(round(ema_slow, 4))),
        ema_trend=Direction(trend),
        rsi_14=Decimal(str(round(rsi_val, 2))),
        macd=Decimal(str(round(macd_line, 4))),
        macd_signal=Decimal(str(round(macd_signal, 4))),
        atr_14=Decimal(str(round(atr_val, 4))),
        realized_vol_annualized=Decimal(str(round(vol_val, 4))),
        iv_percentile=None,  # requires historical IV samples the demo/paper feed doesn't supply -- see technical.iv_percentile
        relative_volume=Decimal(str(round(rel_vol, 3))),
        bid_ask_spread_pct=Decimal(str(round(float(spread_pct), 4))) if spread_pct is not None else None,
        days_to_next_event=None,
        warnings=warnings,
    )


def heuristic_confidence(features: TechnicalFeatures) -> tuple[float, str]:
    """Transparent, documented formula -- NOT a calibrated probability.

    Base 0.5, plus up to +0.25 for RSI distance from the neutral 50 line,
    plus up to +0.15 for MACD agreeing with the EMA trend direction, minus
    0.05 per data-quality warning. Clamped to [0.50, 0.95] -- this system
    never claims near-certainty.
    """
    rsi_component = min(abs(float(features.rsi_14) - 50.0) / 50.0, 1.0) * 0.25
    macd_agrees = (
        (features.ema_trend == Direction.BULLISH and features.macd > features.macd_signal)
        or (features.ema_trend == Direction.BEARISH and features.macd < features.macd_signal)
    )
    macd_component = 0.15 if macd_agrees else 0.0
    penalty = 0.05 * len(features.warnings)
    raw = 0.5 + rsi_component + macd_component - penalty
    confidence = max(0.50, min(0.95, raw))
    formula = (
        f"0.50 base + {rsi_component:.2f} (RSI {features.rsi_14} distance from 50) "
        f"+ {macd_component:.2f} (MACD/trend agreement) - {penalty:.2f} (data-quality warnings), "
        f"clamped to [0.50, 0.95]"
    )
    return confidence, formula


async def analyze(
    symbol: str,
    snapshot: MarketDataSnapshot,
    features: TechnicalFeatures,
    llm: LLMProvider,
) -> MarketThesis:
    confidence_f, formula = heuristic_confidence(features)
    from decimal import Decimal

    confidence = Decimal(str(round(confidence_f, 4)))
    direction = features.ema_trend if confidence_f > 0.55 else Direction.NEUTRAL

    evidence = [
        EvidenceItem(label="EMA trend (12/26)", value=features.ema_trend.value, observed_at=features.computed_at, source="deterministic:ema_trend"),
        EvidenceItem(label="RSI(14)", value=str(features.rsi_14), observed_at=features.computed_at, source="deterministic:rsi"),
        EvidenceItem(label="MACD vs signal", value=f"{features.macd} vs {features.macd_signal}", observed_at=features.computed_at, source="deterministic:macd"),
        EvidenceItem(label="Realized volatility (annualized)", value=str(features.realized_vol_annualized), observed_at=features.computed_at, source="deterministic:realized_volatility"),
        EvidenceItem(label="Relative volume", value=str(features.relative_volume), observed_at=features.computed_at, source="deterministic:relative_volume"),
        EvidenceItem(label="Last price", value=str(snapshot.last_price), observed_at=snapshot.quote_time, source=snapshot.data_source),
    ]
    invalidation = [
        f"EMA trend flips away from {features.ema_trend.value}",
        "RSI(14) crosses back through 50 against the current thesis",
        "Realized volatility jumps into a different regime bucket before entry",
    ]

    evidence_block = "\n".join(f"- {e.label}: {e.value}" for e in evidence)
    prompt = (
        f"Symbol: {symbol}\nDirection: {direction.value}\nConfidence: {confidence_f:.0%} "
        f"(heuristic: {formula})\nEvidence:\n{evidence_block}\n\n"
        "Write one short paragraph (2-3 sentences) narrating why this evidence supports the "
        "stated direction. Do not invent any numbers not listed above."
    )
    narrative = await llm.generate(
        system="You are a markets analyst narrating pre-computed technical evidence. Never invent data.",
        user=prompt,
    )

    return MarketThesis(
        symbol=symbol,
        direction=direction,
        confidence=confidence,
        thesis=narrative,
        evidence=evidence,
        invalidation_conditions=invalidation,
        data_quality_warnings=features.warnings,
        generated_at=datetime.now(UTC),
    )
