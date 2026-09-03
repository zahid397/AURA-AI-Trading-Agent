<?php

namespace App\Enums;

/**
 * Option contract type. Matches
 * ai-engine/app/workflows/state.py `OptionType` exactly.
 */
enum OptionType: string
{
    case Call = 'call';
    case Put = 'put';
}
