<?php

namespace App\Enums;

/**
 * Which Alpaca environment a connection targets. Backs
 * `alpaca_connections.environment`. Both values are non-live; enforced by
 * App\Providers\TradingSafetyServiceProvider at boot regardless.
 */
enum AlpacaEnvironment: string
{
    case Paper = 'paper';
    case Demo = 'demo';
}
