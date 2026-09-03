<?php

namespace App\Enums;

/**
 * Options strategy shape. Matches
 * ai-engine/app/workflows/state.py `StrategyType` exactly.
 */
enum StrategyType: string
{
    case BullCallSpread = 'bull_call_spread';
    case BearPutSpread = 'bear_put_spread';
    case IronCondor = 'iron_condor';
    case CoveredCall = 'covered_call';
    case CashSecuredPut = 'cash_secured_put';
}
