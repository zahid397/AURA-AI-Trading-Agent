<?php

namespace App\Enums;

/**
 * Which Alpaca adapter actually submitted an order. Matches
 * ai-engine/app/workflows/state.py `SubmissionChannel` exactly. Recorded on
 * every order so the UI and audit trail never imply MCP did something the
 * direct API actually did (docs/architecture.md).
 */
enum SubmissionChannel: string
{
    case AlpacaApi = 'alpaca_api';
    case AlpacaMcp = 'alpaca_mcp';
    case AlpacaCli = 'alpaca_cli';
    case Demo = 'demo';
}
