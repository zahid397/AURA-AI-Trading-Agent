<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trading environment
    |--------------------------------------------------------------------------
    |
    | This platform is paper-trading / demo only. Must be "paper" or "demo".
    | Enforced at boot by App\Providers\TradingSafetyServiceProvider — anything
    | else throws before the application finishes booting.
    |
    */

    'trading_env' => env('TRADING_ENV', 'paper'),

    /*
    |--------------------------------------------------------------------------
    | Live trading kill-switch
    |--------------------------------------------------------------------------
    |
    | Must be absent or false. This is a hard safety rail, not a soft warning:
    | if this is ever true, or if the configured Alpaca base URL looks like a
    | live-trading endpoint, boot fails immediately.
    |
    */

    'allow_live_trading' => filter_var(env('ALLOW_LIVE_TRADING', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Alpaca
    |--------------------------------------------------------------------------
    */

    'alpaca' => [
        'base_url' => env('ALPACA_BASE_URL', 'https://paper-api.alpaca.markets'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal signed API (Laravel <-> FastAPI ai-engine)
    |--------------------------------------------------------------------------
    */

    'internal' => [
        'signing_secret' => env('AURA_INTERNAL_SIGNING_SECRET'),
        'timestamp_skew_seconds' => (int) env('AURA_INTERNAL_TIMESTAMP_SKEW_SECONDS', 300),
        'ai_engine_base_url' => env('AI_ENGINE_BASE_URL', 'http://localhost:8001'),
        'request_timeout_seconds' => (int) env('AI_ENGINE_TIMEOUT_SECONDS', 10),
        'capabilities_timeout_seconds' => (int) env('AI_ENGINE_CAPABILITIES_TIMEOUT_SECONDS', 3),
    ],

];
