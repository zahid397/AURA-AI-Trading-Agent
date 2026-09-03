<?php

namespace App\Enums;

/**
 * Severity of an individual risk rule result. Matches
 * ai-engine/app/workflows/state.py `RiskSeverity` exactly.
 */
enum RiskSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
