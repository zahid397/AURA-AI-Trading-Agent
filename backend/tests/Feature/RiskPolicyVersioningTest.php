<?php

namespace Tests\Feature;

use App\Models\RiskPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskPolicyVersioningTest extends TestCase
{
    use RefreshDatabase;

    private const LIMITS = [
        'max_daily_loss_pct' => 3.0,
        'max_drawdown_pct' => 10.0,
        'max_position_risk_pct' => 2.0,
        'max_total_exposure_pct' => 50.0,
        'max_symbol_exposure_pct' => 15.0,
        'min_confidence' => 0.65,
        'max_quote_age_seconds' => 60,
        'min_open_interest' => 100,
        'max_spread_pct' => 10.0,
        'max_open_positions' => 10,
    ];

    public function test_patch_creates_a_new_version_and_leaves_v1_readable(): void
    {
        $user = User::factory()->create();

        RiskPolicy::query()->create([
            'version' => 1,
            'name' => 'v1 policy',
            'limits' => self::LIMITS,
            'active' => true,
            'created_by' => $user->id,
        ]);

        // 2.5 (not a whole number) deliberately, so the assertion below
        // also proves the limit survives the JSON/jsonb round trip without
        // being coerced to an integer.
        $newLimits = array_merge(self::LIMITS, ['max_daily_loss_pct' => 2.5, 'max_open_positions' => 8]);

        $response = $this->actingAs($user)->patchJson('/api/v1/risk/policy', [
            'name' => 'Tighter risk policy',
            'limits' => $newLimits,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.version', 2);
        $response->assertJsonPath('data.active', true);
        $response->assertJsonPath('data.limits.max_daily_loss_pct', 2.5);

        $this->assertSame(2, RiskPolicy::count());
        $this->assertDatabaseHas('risk_policies', ['version' => 1, 'active' => false]);
        $this->assertDatabaseHas('risk_policies', ['version' => 2, 'active' => true]);
    }

    public function test_v1_is_still_readable_by_version_after_v2_is_created(): void
    {
        $user = User::factory()->create();

        RiskPolicy::query()->create([
            'version' => 1,
            'name' => 'v1 policy',
            'limits' => self::LIMITS,
            'active' => true,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->patchJson('/api/v1/risk/policy', [
            'name' => 'v2 policy',
            'limits' => array_merge(self::LIMITS, ['max_open_positions' => 5]),
        ])->assertStatus(201);

        // The superseded version must still be fetchable by version number...
        $this->actingAs($user)->getJson('/api/v1/risk/policy?version=1')
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.limits.max_open_positions', 10);

        // ...while the default (no query param) now resolves to the new
        // active version, never a mutated v1.
        $this->actingAs($user)->getJson('/api/v1/risk/policy')
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.limits.max_open_positions', 5);
    }

    public function test_patch_never_mutates_the_previously_active_row_in_place(): void
    {
        $user = User::factory()->create();

        $v1 = RiskPolicy::query()->create([
            'version' => 1,
            'name' => 'v1 policy',
            'limits' => self::LIMITS,
            'active' => true,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->patchJson('/api/v1/risk/policy', [
            'limits' => array_merge(self::LIMITS, ['min_confidence' => 0.9]),
        ])->assertStatus(201);

        $v1->refresh();

        $this->assertFalse($v1->active);
        // v1's own limits are untouched by the v2 update.
        $this->assertSame(0.65, (float) $v1->limits['min_confidence']);
    }
}
