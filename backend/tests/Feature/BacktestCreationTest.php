<?php

namespace Tests\Feature;

use App\Enums\BacktestStatus;
use App\Models\BacktestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the full backtest lifecycle: POST /backtests previously required a
 * flat `start_date`/`end_date`/`starting_equity` payload the Backtest
 * Builder form never sent (it sends `date_range.from`/`.to` and
 * `starting_capital` instead), so every real submission 422'd before
 * reaching the controller. Once that's fixed, results still never appeared
 * because nothing polled the ai-engine back or persisted them -- these
 * tests lock in both fixes end-to-end. QUEUE_CONNECTION=sync in phpunit.xml
 * means App\Jobs\StartBacktest runs inline within the POST request itself,
 * so DB state can be asserted immediately after.
 */
class BacktestCreationTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_PAYLOAD = [
        'strategy' => 'bull_call_spread',
        'symbols' => ['SPY'],
        'date_range' => ['from' => '2026-01-01', 'to' => '2026-06-01'],
        'starting_capital' => '100000',
        'dte' => ['min' => 21, 'max' => 45],
        'entry_rules' => 'Enter on bullish regime confirmation with IV percentile below 50.',
        'exit_rules' => 'Exit at 50% of max profit or 7 DTE, whichever first.',
        'confidence_threshold' => 0.55,
        'slippage_pct' => 0.02,
        'risk_policy_version' => 1,
    ];

    private const AI_ENGINE_RESULT = [
        'data_source' => 'simulated_fixture',
        'symbols' => ['SPY'],
        'trade_count' => 2,
        'win_rate' => '0.5000',
        'profit_factor' => '1.5000',
        'total_return_pct' => '0.0250',
        'max_drawdown_pct' => '0.0100',
        'equity_curve' => ['100000', '100500.00', '100250.00'],
        'assumptions' => ['note' => 'single-net-premium proxy'],
        'trades' => [
            ['symbol' => 'SPY', 'strategy_type' => 'bull_call_spread', 'entry_date' => '2026-02-10', 'exit_date' => '2026-02-20', 'entry_price' => '2.50', 'exit_price' => '3.00', 'pnl' => '500.00', 'exit_reason' => 'profit_target'],
            ['symbol' => 'SPY', 'strategy_type' => 'bull_call_spread', 'entry_date' => '2026-03-01', 'exit_date' => '2026-03-10', 'entry_price' => '2.00', 'exit_price' => '1.75', 'pnl' => '-250.00', 'exit_reason' => 'stop_loss'],
        ],
    ];

    /** Fakes both hops: the POST that starts the run, and the GET the polling loop uses to check on it. */
    private function fakeAiEngineCompleting(): void
    {
        Http::fake([
            '*/internal/v1/backtests' => Http::response(['backtest_id' => null, 'status' => 'running'], 200),
            '*/internal/v1/backtests/*' => Http::response(['status' => 'completed', 'result' => self::AI_ENGINE_RESULT], 200),
        ]);
    }

    public function test_accepts_the_exact_payload_shape_the_frontend_sends(): void
    {
        $this->fakeAiEngineCompleting();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/backtests', self::FRONTEND_PAYLOAD);

        $response->assertStatus(202);
        $this->assertSame(1, BacktestRun::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('backtest_runs', ['user_id' => $user->id]);
    }

    public function test_still_rejects_a_missing_date_range(): void
    {
        $user = User::factory()->create();
        $payload = self::FRONTEND_PAYLOAD;
        unset($payload['date_range']);

        $this->actingAs($user)->postJson('/api/v1/backtests', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.date_range.0', fn ($message) => is_string($message));
    }

    public function test_forwards_a_flat_payload_to_the_ai_engine_not_the_stored_config_shape(): void
    {
        $this->fakeAiEngineCompleting();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/backtests', self::FRONTEND_PAYLOAD)
            ->assertStatus(202);

        // The ai-engine's BacktestCreateRequest is a strict Pydantic model
        // (extra="forbid") expecting these fields flat, not nested under a
        // `config` key -- asserting the actual outbound shape here is what
        // would have caught the original mismatch.
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/internal/v1/backtests')) {
                return false;
            }

            return $request['symbols'] === ['SPY']
                && $request['strategy_types'] === ['bull_call_spread']
                && $request['start_date'] === '2026-01-01'
                && $request['end_date'] === '2026-06-01'
                && $request['starting_capital'] === '100000'
                && $request['min_dte'] === 21
                && $request['max_dte'] === 45
                && ! isset($request['config'])
                && ! isset($request['user_id']);
        });
    }

    public function test_polls_the_ai_engine_and_persists_the_completed_result(): void
    {
        $this->fakeAiEngineCompleting();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/backtests', self::FRONTEND_PAYLOAD)->assertStatus(202);

        $backtestRun = BacktestRun::query()->where('user_id', $user->id)->firstOrFail();
        $backtestRun->refresh();

        $this->assertSame(BacktestStatus::Completed, $backtestRun->status);
        $this->assertNotNull($backtestRun->completed_at);

        $this->assertDatabaseCount('backtest_results', 1);
        $this->assertDatabaseCount('backtest_trades', 2);
        $this->assertDatabaseHas('backtest_trades', ['backtest_run_id' => $backtestRun->id, 'symbol' => 'SPY', 'exit_reason' => 'profit_target']);
        $this->assertDatabaseHas('backtest_trades', ['backtest_run_id' => $backtestRun->id, 'symbol' => 'SPY', 'exit_reason' => 'stop_loss']);

        $result = $backtestRun->results()->first();
        $this->assertSame('0.5000', $result->metrics['win_rate']);
        $this->assertSame(2, $result->metrics['trade_count']);
        $this->assertCount(3, $result->equity_curve);
        $this->assertCount(3, $result->drawdown_curve);
        $this->assertNotEmpty($result->return_distribution);
        $this->assertNotEmpty($result->monthly_returns);
    }

    public function test_marks_the_run_failed_when_the_ai_engine_reports_failure(): void
    {
        Http::fake([
            '*/internal/v1/backtests' => Http::response(['backtest_id' => null, 'status' => 'running'], 200),
            '*/internal/v1/backtests/*' => Http::response(['status' => 'failed', 'result' => null], 200),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/backtests', self::FRONTEND_PAYLOAD)->assertStatus(202);

        $backtestRun = BacktestRun::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(BacktestStatus::Failed, $backtestRun->status);
        $this->assertDatabaseCount('backtest_results', 0);
        $this->assertDatabaseCount('backtest_trades', 0);
    }

    public function test_show_endpoint_returns_the_persisted_result_and_trades(): void
    {
        $this->fakeAiEngineCompleting();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/backtests', self::FRONTEND_PAYLOAD)->assertStatus(202);
        $backtestRun = BacktestRun::query()->where('user_id', $user->id)->firstOrFail();

        $response = $this->actingAs($user)->getJson("/api/v1/backtests/{$backtestRun->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed')
            // `result` singular, not `results` -- a run only ever produces one.
            ->assertJsonPath('data.result.metrics.win_rate', '0.5000')
            ->assertJsonPath('data.result.metrics.trade_count', 2)
            ->assertJsonCount(2, 'data.trades')
            ->assertJsonPath('data.trades.0.symbol', 'SPY');
    }
}
