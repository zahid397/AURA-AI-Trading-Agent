<?php

namespace App\Enums;

/**
 * Buy/sell side of a leg, order leg, or fill. Matches
 * ai-engine/app/workflows/state.py `OrderSide` exactly.
 */
enum OrderSide: string
{
    case Buy = 'buy';
    case Sell = 'sell';
}
