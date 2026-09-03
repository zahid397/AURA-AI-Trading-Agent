<?php

namespace Tests\Feature;

use App\Models\PortfolioSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /portfolio/summary previously nested equity/cash/buying_power/etc.
 * under a `latest` key, but the frontend's PortfolioSummary type reads them
 * flat -- so Portfolio's four top KPI cards (Equity, Unrealized/Realized
 * P&L, Buying Power, Options Exposure) stayed permanently blank even when
 * real snapshot data existed. This locks in the fixed, flat shape.
 */
class PortfolioSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_latest_snapshot_fields_flat_not_nested(): void
    {
        $user = User::factory()->create();

        PortfolioSnapshot::query()->create([
            'user_id' => $user->id,
            'snapshot_at' => now()->subDays(5),
            'equity' => '100000.0000',
            'cash' => '20000.0000',
            'buying_power' => '40000.0000',
            'unrealized_pl' => '0.0000',
            'realized_pl' => '0.0000',
            'options_exposure' => '0.0000',
        ]);

        PortfolioSnapshot::query()->create([
            'user_id' => $user->id,
            'snapshot_at' => now(),
            'equity' => '100245.6900',
            'cash' => '21875.2400',
            'buying_power' => '78120.4500',
            'unrealized_pl' => '182.3400',
            'realized_pl' => '63.3500',
            'options_exposure' => '350.0000',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/portfolio/summary');

        $response->assertOk()
            ->assertJsonPath('data.equity', '100245.6900')
            ->assertJsonPath('data.buying_power', '78120.4500')
            ->assertJsonPath('data.unrealized_pl', '182.3400')
            ->assertJsonPath('data.realized_pl', '63.3500')
            ->assertJsonPath('data.options_exposure', '350.0000')
            ->assertJsonPath('data.starting_equity', '100000.0000')
            ->assertJsonMissingPath('data.latest');
    }

    public function test_returns_null_data_for_a_brand_new_account_with_no_snapshots(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/portfolio/summary')
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}
