<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use App\Models\RiskPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeActivePolicy(array $limits): RiskPolicy
    {
        return RiskPolicy::query()->create([
            'version' => 1,
            'name' => 'Test policy',
            'limits' => $limits,
            'active' => true,
        ]);
    }

    private function makeSnapshot(User $user, string $equity, string $optionsExposure, \DateTimeInterface $at): PortfolioSnapshot
    {
        return PortfolioSnapshot::query()->create([
            'user_id' => $user->id,
            'snapshot_at' => $at,
            'equity' => $equity,
            'cash' => $equity,
            'buying_power' => $equity,
            'unrealized_pl' => '0.0000',
            'realized_pl' => '0.0000',
            'options_exposure' => $optionsExposure,
        ]);
    }

    public function test_overview_degrades_gracefully_with_no_portfolio_history_or_policy(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/risk/overview');

        $response->assertOk();
        // json_encode() collapses whole-number floats (0.0 -> 0), so these
        // compare against the actual wire values, not PHP's in-memory types.
        $response->assertJsonPath('data.risk_score', 0);
        $response->assertJsonPath('data.risk_level', 'LOW');
        $response->assertJsonPath('data.daily_pl', null);
        $response->assertJsonPath('data.daily_loss_limit', null);
        $response->assertJsonPath('data.exposure_pct', 0);
        $response->assertJsonPath('data.drawdown_series', []);
    }

    public function test_overview_computes_risk_score_and_level_from_exposure_against_the_active_policy(): void
    {
        $user = User::factory()->create();

        $this->makeActivePolicy([
            'max_daily_loss_pct' => 3.0,
            'max_drawdown_pct' => 10.0,
            'max_position_risk_pct' => 2.0,
            'max_total_exposure_pct' => 25.0,
            'max_symbol_exposure_pct' => 15.0,
        ]);

        // Equity 100,000 with options exposure of 20,000 -> 20% utilized
        // against a 25% policy limit -> 80% of that guardrail used.
        $this->makeSnapshot($user, '100000.0000', '20000.0000', now());

        $response = $this->actingAs($user)->getJson('/api/v1/risk/overview');

        $response->assertOk();
        $response->assertJsonPath('data.exposure_pct', 20);
        $response->assertJsonPath('data.max_exposure_pct', 25);
        $response->assertJsonPath('data.risk_score', 80);
        $response->assertJsonPath('data.risk_level', 'ELEVATED');
        $response->assertJsonPath('data.daily_loss_limit', '3000.0000');
        $response->assertJsonPath('data.position_sizing_budget', '2000.0000');

        $riskScore = $response->json('data.risk_score');
        $this->assertIsNumeric($riskScore);
        $this->assertTrue(is_finite((float) $riskScore));
    }

    public function test_overview_daily_pl_is_the_delta_from_the_previous_snapshot(): void
    {
        $user = User::factory()->create();
        $this->makeActivePolicy(['max_daily_loss_pct' => 3.0, 'max_drawdown_pct' => 10.0, 'max_position_risk_pct' => 2.0, 'max_total_exposure_pct' => 25.0, 'max_symbol_exposure_pct' => 15.0]);

        $this->makeSnapshot($user, '100000.0000', '0.0000', now()->subDay());
        $this->makeSnapshot($user, '100150.5000', '0.0000', now());

        $response = $this->actingAs($user)->getJson('/api/v1/risk/overview');

        $response->assertOk();
        $response->assertJsonPath('data.daily_pl', '150.5000');
    }

    public function test_overview_kill_switch_engaged_reflects_whether_any_agent_is_enabled(): void
    {
        $user = User::factory()->create();
        Agent::query()->create(['type' => 'execution', 'enabled' => false, 'status' => 'paused', 'config_version' => 1]);

        $this->actingAs($user)->getJson('/api/v1/risk/overview')
            ->assertOk()
            ->assertJsonPath('data.kill_switch_engaged', true);

        Agent::query()->create(['type' => 'market_analyst', 'enabled' => true, 'status' => 'idle', 'config_version' => 1]);

        $this->actingAs($user)->getJson('/api/v1/risk/overview')
            ->assertOk()
            ->assertJsonPath('data.kill_switch_engaged', false);
    }

    public function test_overview_breaks_down_exposure_by_underlying_and_strategy_for_open_positions_only(): void
    {
        $user = User::factory()->create();
        $this->makeSnapshot($user, '100000.0000', '0.0000', now());

        Position::query()->create([
            'user_id' => $user->id,
            'symbol' => 'SPY',
            'legs' => [],
            'quantity' => 1,
            'avg_entry_price' => '10.0000',
            'current_value' => '1000.0000',
            'unrealized_pl' => '0.0000',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        Position::query()->create([
            'user_id' => $user->id,
            'symbol' => 'TSLA',
            'legs' => [],
            'quantity' => 1,
            'avg_entry_price' => '10.0000',
            'current_value' => '500.0000',
            'unrealized_pl' => '0.0000',
            'status' => 'closed',
            'opened_at' => now(),
            'closed_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/risk/overview');

        $response->assertOk();
        $exposureByUnderlying = $response->json('data.exposure_by_underlying');
        $this->assertCount(1, $exposureByUnderlying);
        $this->assertSame('SPY', $exposureByUnderlying[0]['symbol']);
        $this->assertSame('1000.0000', $exposureByUnderlying[0]['exposure']);
        $this->assertSame(1, $exposureByUnderlying[0]['pct']);
    }
}
