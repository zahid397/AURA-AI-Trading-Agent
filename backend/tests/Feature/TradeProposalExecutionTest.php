<?php

namespace Tests\Feature;

use App\Enums\TradeProposalStatus;
use App\Models\AgentRun;
use App\Models\Order;
use App\Models\Strategy;
use App\Models\StrategyLeg;
use App\Models\TradeProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TradeProposalExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(User $user, string $status): TradeProposal
    {
        $run = AgentRun::query()->create([
            'user_id' => $user->id,
            'trigger' => 'manual',
            'mode' => 'approval_required',
            'status' => 'completed',
            'trace_id' => (string) Str::uuid(),
            'symbol_universe' => ['SPY'],
        ]);

        $strategy = Strategy::query()->create([
            'agent_run_id' => $run->id,
            'symbol' => 'SPY',
            'strategy_type' => 'bull_call_spread',
            'regime' => 'bullish',
            'thesis' => 'Test thesis.',
            'confidence' => '0.8000',
            'max_profit' => '100.0000',
            'max_loss' => '50.0000',
            'breakevens' => ['580'],
            'probability_of_profit' => '0.6000',
            'liquidity_score' => '0.9000',
            'score' => '0.8000',
            'score_breakdown' => [],
            'quote_timestamp' => now(),
            'dte' => 30,
            'selected' => true,
        ]);

        StrategyLeg::query()->create([
            'strategy_id' => $strategy->id,
            'action' => 'buy',
            'occ_symbol' => 'SPY251231C00580000',
            'underlying' => 'SPY',
            'option_type' => 'call',
            'strike' => '580.0000',
            'expiration' => now()->addDays(30)->toDateString(),
            'position_intent' => 'buy_to_open',
            'ratio_qty' => 1,
            'quote_bid' => '9.00',
            'quote_ask' => '9.20',
            'quote_time' => now(),
        ]);

        return TradeProposal::query()->create([
            'agent_run_id' => $run->id,
            'strategy_id' => $strategy->id,
            'user_id' => $user->id,
            'status' => $status,
            'expires_at' => now()->addHours(4),
        ]);
    }

    public function test_approve_then_execute_creates_exactly_one_order(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal($user, 'pending');

        $this->actingAs($user)
            ->postJson("/api/v1/trade-proposals/{$proposal->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $response = $this->actingAs($user)->postJson(
            "/api/v1/trade-proposals/{$proposal->id}/execute",
            [],
            ['Idempotency-Key' => 'exec-key-1']
        );

        $response->assertStatus(201);
        $this->assertSame(1, Order::count());
        $this->assertTrue($proposal->fresh()->status === TradeProposalStatus::Executed);
    }

    public function test_replaying_the_same_idempotency_key_returns_the_same_order_without_duplicating(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal($user, 'approved');

        $first = $this->actingAs($user)->postJson(
            "/api/v1/trade-proposals/{$proposal->id}/execute",
            [],
            ['Idempotency-Key' => 'exec-key-1']
        );
        $first->assertStatus(201);
        $orderId = $first->json('data.id');

        $second = $this->actingAs($user)->postJson(
            "/api/v1/trade-proposals/{$proposal->id}/execute",
            [],
            ['Idempotency-Key' => 'exec-key-1']
        );

        $second->assertOk();
        $second->assertJsonPath('data.id', $orderId);
        $this->assertSame(1, Order::count());
    }

    public function test_a_second_distinct_execute_after_already_executed_is_rejected(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal($user, 'approved');

        $this->actingAs($user)->postJson(
            "/api/v1/trade-proposals/{$proposal->id}/execute",
            [],
            ['Idempotency-Key' => 'first-key']
        )->assertStatus(201);

        $response = $this->actingAs($user)->postJson(
            "/api/v1/trade-proposals/{$proposal->id}/execute",
            [],
            ['Idempotency-Key' => 'a-completely-different-key']
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'PROPOSAL_ALREADY_EXECUTED');
        $this->assertSame(1, Order::count());
    }

    public function test_execute_requires_the_idempotency_key_header(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal($user, 'approved');

        $response = $this->actingAs($user)->postJson("/api/v1/trade-proposals/{$proposal->id}/execute");

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->assertSame(0, Order::count());
    }

    public function test_cannot_execute_a_proposal_that_has_not_been_approved(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal($user, 'pending');

        $response = $this->actingAs($user)->postJson(
            "/api/v1/trade-proposals/{$proposal->id}/execute",
            [],
            ['Idempotency-Key' => 'key-1']
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'PROPOSAL_NOT_APPROVED');
    }

    public function test_cannot_execute_an_expired_proposal(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal($user, 'approved');
        $proposal->update(['expires_at' => now()->subMinute()]);

        $response = $this->actingAs($user)->postJson(
            "/api/v1/trade-proposals/{$proposal->id}/execute",
            [],
            ['Idempotency-Key' => 'key-1']
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'PROPOSAL_EXPIRED');
        $this->assertSame(0, Order::count());
    }

    public function test_reject_sets_status_and_reason(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal($user, 'pending');

        $response = $this->actingAs($user)->postJson("/api/v1/trade-proposals/{$proposal->id}/reject", [
            'reason' => 'Thesis no longer valid.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'rejected');
        $response->assertJsonPath('data.rejected_reason', 'Thesis no longer valid.');
    }

    public function test_cannot_approve_a_proposal_that_is_not_pending(): void
    {
        $user = User::factory()->create();
        $proposal = $this->makeProposal($user, 'rejected');

        $response = $this->actingAs($user)->postJson("/api/v1/trade-proposals/{$proposal->id}/approve");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'PROPOSAL_NOT_PENDING');
    }

    public function test_a_user_cannot_act_on_another_users_proposal(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $proposal = $this->makeProposal($owner, 'pending');

        $response = $this->actingAs($intruder)->postJson("/api/v1/trade-proposals/{$proposal->id}/approve");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
