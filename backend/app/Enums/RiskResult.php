<?php

namespace App\Enums;

/**
 * Overall outcome of a risk evaluation. Matches
 * ai-engine/app/workflows/state.py `RiskResult` exactly.
 */
enum RiskResult: string
{
    case Approved = 'approved';
    case ApprovalRequired = 'approval_required';
    case Rejected = 'rejected';
}
