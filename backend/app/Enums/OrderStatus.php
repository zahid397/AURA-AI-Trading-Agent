<?php

namespace App\Enums;

/**
 * Broker order lifecycle status. Matches
 * ai-engine/app/workflows/state.py `OrderStatus` exactly.
 */
enum OrderStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case PartiallyFilled = 'partially_filled';
    case Filled = 'filled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case UnknownReconciling = 'unknown_reconciling';
}
