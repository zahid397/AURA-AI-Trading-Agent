<?php

namespace Tests\Unit;

use App\Providers\TradingSafetyServiceProvider;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * Exercises the exact boot-time guard logic that runs when Laravel boots
 * App\Providers\TradingSafetyServiceProvider: it must throw and prevent
 * the app from finishing boot whenever the configuration could allow a
 * live trade, and it must boot silently otherwise.
 */
class TradingSafetyServiceProviderTest extends TestCase
{
    public function test_boot_fails_when_trading_env_is_not_paper_or_demo(): void
    {
        Config::set('aura.trading_env', 'live');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("TRADING_ENV must be 'paper' or 'demo'");

        (new TradingSafetyServiceProvider($this->app))->boot();
    }

    public function test_boot_fails_when_live_trading_is_allowed(): void
    {
        Config::set('aura.allow_live_trading', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ALLOW_LIVE_TRADING must be absent or false');

        (new TradingSafetyServiceProvider($this->app))->boot();
    }

    public function test_boot_fails_when_alpaca_base_url_is_the_live_endpoint(): void
    {
        Config::set('aura.alpaca.base_url', 'https://api.alpaca.markets');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('looks like a live-trading');

        (new TradingSafetyServiceProvider($this->app))->boot();
    }

    public function test_boot_fails_when_alpaca_base_url_is_the_live_endpoint_case_insensitively(): void
    {
        Config::set('aura.alpaca.base_url', 'HTTPS://API.ALPACA.MARKETS');

        $this->expectException(RuntimeException::class);

        (new TradingSafetyServiceProvider($this->app))->boot();
    }

    public function test_boot_succeeds_with_safe_paper_trading_configuration(): void
    {
        Config::set('aura.trading_env', 'paper');
        Config::set('aura.allow_live_trading', false);
        Config::set('aura.alpaca.base_url', 'https://paper-api.alpaca.markets');

        (new TradingSafetyServiceProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }

    public function test_boot_succeeds_in_demo_trading_env(): void
    {
        Config::set('aura.trading_env', 'demo');
        Config::set('aura.allow_live_trading', false);
        Config::set('aura.alpaca.base_url', 'https://paper-api.alpaca.markets');

        (new TradingSafetyServiceProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }
}
