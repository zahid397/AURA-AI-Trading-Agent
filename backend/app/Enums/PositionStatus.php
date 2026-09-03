<?php

namespace App\Enums;

/**
 * Backs `positions.status`.
 */
enum PositionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
