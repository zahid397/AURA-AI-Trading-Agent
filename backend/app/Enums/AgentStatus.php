<?php

namespace App\Enums;

/**
 * Backs `agents.status`.
 */
enum AgentStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Paused = 'paused';
    case Error = 'error';
}
