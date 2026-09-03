<?php

namespace App\Enums;

/**
 * Backs `agent_logs.level`.
 */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
}
