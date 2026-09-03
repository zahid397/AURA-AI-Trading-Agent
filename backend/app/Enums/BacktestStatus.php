<?php

namespace App\Enums;

/**
 * Backs `backtest_runs.status`.
 */
enum BacktestStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
