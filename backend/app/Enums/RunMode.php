<?php

namespace App\Enums;

/**
 * Trading autonomy level for an agent run / user default.
 *
 * String values are part of the internal API contract shared with the
 * FastAPI ai-engine (ai-engine/app/workflows/state.py `RunMode`) — they
 * must match exactly since they cross the wire as JSON strings.
 */
enum RunMode: string
{
    case Demo = 'demo';
    case Observe = 'observe';
    case ApprovalRequired = 'approval_required';
    case PaperAutonomous = 'paper_autonomous';
}
