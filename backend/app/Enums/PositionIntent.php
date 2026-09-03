<?php

namespace App\Enums;

/**
 * Opening/closing intent for an options leg. Matches
 * ai-engine/app/workflows/state.py `PositionIntent` exactly.
 */
enum PositionIntent: string
{
    case BuyToOpen = 'buy_to_open';
    case BuyToClose = 'buy_to_close';
    case SellToOpen = 'sell_to_open';
    case SellToClose = 'sell_to_close';
}
