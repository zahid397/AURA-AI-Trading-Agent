<?php

namespace App\Enums;

/**
 * The five specialised agents in the LangGraph workflow. Backs
 * `agents.type` and `agent_logs.agent_type`.
 */
enum AgentType: string
{
    case MarketAnalyst = 'market_analyst';
    case OptionsStrategy = 'options_strategy';
    case RiskGuardian = 'risk_guardian';
    case Execution = 'execution';
    case Learning = 'learning';
}
