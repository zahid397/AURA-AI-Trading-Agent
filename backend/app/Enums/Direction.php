<?php

namespace App\Enums;

/**
 * Directional market thesis / regime for a strategy.
 *
 * Backs the `strategies.regime` column. Matches
 * ai-engine/app/workflows/state.py `Direction` exactly.
 */
enum Direction: string
{
    case Bullish = 'bullish';
    case Bearish = 'bearish';
    case Neutral = 'neutral';
}
