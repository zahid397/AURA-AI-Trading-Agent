<?php

namespace App\Enums;

/**
 * What caused an agent run to start.
 *
 * Matches ai-engine/app/workflows/state.py `RunTrigger` exactly.
 */
enum RunTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case ApprovalResume = 'approval_resume';
}
