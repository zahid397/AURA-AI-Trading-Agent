<?php

namespace App\Enums;

/**
 * Lifecycle status of an agent_runs row.
 *
 * Matches ai-engine/app/workflows/state.py `RunStatus` exactly.
 */
enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
}
