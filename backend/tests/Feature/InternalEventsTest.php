<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Http\Middleware\VerifyInternalSignature;
use App\Models\AgentEvent;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InternalEventsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): AgentRun
    {
        $user = User::factory()->create();

        return AgentRun::query()->create([
            'user_id' => $user->id,
            'trigger' => 'manual',
            'mode' => 'approval_required',
            'status' => 'pending',
            'trace_id' => (string) Str::uuid(),
            'symbol_universe' => ['SPY'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sign(array $body, ?int $timestamp = null): array
    {
        $rawBody = json_encode($body);
        $timestamp ??= time();
        $secret = (string) config('aura.internal.signing_secret');
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return [
            VerifyInternalSignature::TIMESTAMP_HEADER => (string) $timestamp,
            VerifyInternalSignature::SIGNATURE_HEADER => $signature,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function postEvent(array $body, array $headers): TestResponse
    {
        return $this->postJson('/api/internal/v1/events', $body, $headers);
    }

    private function runStartedPayload(AgentRun $run, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? (string) Str::uuid(),
            'run_id' => $run->id,
            'trace_id' => (string) Str::uuid(),
            'type' => 'run.started',
            'payload' => [],
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    public function test_a_validly_signed_event_is_accepted_and_persisted(): void
    {
        $run = $this->makeRun();
        $body = $this->runStartedPayload($run);

        $response = $this->postEvent($body, $this->sign($body));

        $response->assertOk();
        $response->assertJsonPath('data.status', 'processed');

        $this->assertDatabaseHas('agent_events', [
            'event_id' => $body['event_id'],
            'agent_run_id' => $run->id,
            'type' => 'run.started',
            'signature_valid' => true,
        ]);

        $this->assertSame(RunStatus::Running, $run->fresh()->status);
    }

    public function test_a_bad_signature_is_rejected(): void
    {
        $run = $this->makeRun();
        $body = $this->runStartedPayload($run);
        $headers = $this->sign($body);
        $headers[VerifyInternalSignature::SIGNATURE_HEADER] = 'not-the-right-signature';

        $response = $this->postEvent($body, $headers);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_SIGNATURE');
        $this->assertSame(0, AgentEvent::count());
    }

    public function test_missing_signature_headers_are_rejected(): void
    {
        $run = $this->makeRun();
        $body = $this->runStartedPayload($run);

        $response = $this->postEvent($body, []);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'MISSING_SIGNATURE');
        $this->assertSame(0, AgentEvent::count());
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $run = $this->makeRun();
        $body = $this->runStartedPayload($run);
        $staleTimestamp = time() - 600; // 10 minutes old, beyond the 5-minute skew window

        $response = $this->postEvent($body, $this->sign($body, $staleTimestamp));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'STALE_TIMESTAMP');
        $this->assertSame(0, AgentEvent::count());
    }

    public function test_a_replayed_event_id_is_a_no_op_and_returns_200(): void
    {
        $run = $this->makeRun();
        $eventId = (string) Str::uuid();
        $body = $this->runStartedPayload($run, $eventId);

        $this->postEvent($body, $this->sign($body))->assertOk();
        $this->assertSame(1, AgentEvent::count());

        // Replay the identical event_id (a fresh signature over the same
        // body is fine — the point is the event_id was already processed).
        $replay = $this->postEvent($body, $this->sign($body));

        $replay->assertOk();
        $replay->assertJsonPath('data.status', 'duplicate_ignored');
        $this->assertSame(1, AgentEvent::count());
    }

    public function test_unknown_run_id_is_rejected(): void
    {
        $body = [
            'event_id' => (string) Str::uuid(),
            'run_id' => (string) Str::uuid(),
            'trace_id' => (string) Str::uuid(),
            'type' => 'run.started',
            'payload' => [],
            'occurred_at' => now()->toIso8601String(),
        ];

        $response = $this->postEvent($body, $this->sign($body));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'UNKNOWN_RUN');
    }

    public function test_this_route_does_not_require_sanctum_authentication(): void
    {
        // No actingAs() call at all — this is a service-to-service route
        // authenticated purely by the HMAC signature.
        $run = $this->makeRun();
        $body = $this->runStartedPayload($run);

        $this->postEvent($body, $this->sign($body))->assertOk();
    }
}
