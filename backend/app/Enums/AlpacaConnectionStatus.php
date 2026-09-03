<?php

namespace App\Enums;

/**
 * Backs `alpaca_connections.status`.
 */
enum AlpacaConnectionStatus: string
{
    case NotConfigured = 'not_configured';
    case Connected = 'connected';
    case Error = 'error';
}
