<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Hard safety rail for the whole platform: this is a paper/demo trading
 * system and must never be able to boot in a configuration that could place
 * a live trade. There is deliberately no "warn and continue" path here — a
 * misconfiguration must fail the boot process outright.
 */
class TradingSafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->assertPaperOrDemoTradingEnv();
        $this->assertLiveTradingNotAllowed();
        $this->assertAlpacaBaseUrlIsNotLive();
    }

    private function assertPaperOrDemoTradingEnv(): void
    {
        $tradingEnv = config('aura.trading_env');

        if (! in_array($tradingEnv, ['paper', 'demo'], true)) {
            throw new RuntimeException(
                "Refusing to boot: TRADING_ENV must be 'paper' or 'demo', got '{$tradingEnv}'. ".
                'This platform never places live trades.'
            );
        }
    }

    private function assertLiveTradingNotAllowed(): void
    {
        if (config('aura.allow_live_trading') === true) {
            throw new RuntimeException(
                'Refusing to boot: ALLOW_LIVE_TRADING must be absent or false. '.
                'This platform never places live trades.'
            );
        }
    }

    private function assertAlpacaBaseUrlIsNotLive(): void
    {
        $baseUrl = (string) config('aura.alpaca.base_url');

        // Alpaca's live trading base URL is api.alpaca.markets (no "paper-"
        // prefix). Anything matching that host, configured for this app, is
        // a live-trading endpoint and must never be reachable from here.
        if (preg_match('/(^|\/\/)api\.alpaca\.markets/i', $baseUrl) === 1) {
            throw new RuntimeException(
                "Refusing to boot: ALPACA_BASE_URL ('{$baseUrl}') looks like a live-trading ".
                'endpoint. Only the paper endpoint (paper-api.alpaca.markets) is allowed.'
            );
        }
    }
}
