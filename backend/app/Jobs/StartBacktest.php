<?php

namespace App\Jobs;

use App\Enums\BacktestStatus;
use App\Models\BacktestRun;
use App\Services\AiEngine\AiEngineClient;
use App\Services\Backtesting\BacktestResultMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts a backtest on the FastAPI ai-engine (POST /internal/v1/backtests,
 * per docs/architecture.md), then polls it (GET /internal/v1/backtests/{id})
 * until it reaches a terminal status and persists the result. Queued for
 * the same reason as App\Jobs\StartAgentRun: an unreachable ai-engine must
 * never block the HTTP response, and the backtest_runs row already exists
 * with status=pending for the frontend to poll in the meantime.
 *
 * The engine computes fast (documented as sub-second to a few seconds for
 * this fixture-scale build — see ai-engine/app/backtesting/engine.py), so a
 * short bounded poll loop inside this same job is enough; there's no
 * separate push-back channel from the ai-engine for this endpoint.
 */
class StartBacktest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_POLL_ATTEMPTS = 15;

    private const POLL_INTERVAL_MS = 500;

    public function __construct(public readonly string $backtestRunId)
    {
        //
    }

    public function handle(AiEngineClient $client, BacktestResultMapper $mapper): void
    {
        $backtestRun = BacktestRun::query()->find($this->backtestRunId);

        if (! $backtestRun) {
            return;
        }

        $config = $backtestRun->config;

        try {
            // The ai-engine's BacktestCreateRequest is a strict Pydantic model
            // (extra="forbid") expecting these fields flat at the top level --
            // not nested under `config`, and not carrying `user_id`/`seed_hash`,
            // which it has no field for and would reject the whole request over.
            $client->startBacktest([
                'backtest_id' => $backtestRun->id,
                'symbols' => $config['symbols'] ?? [],
                'strategy_types' => array_filter([$config['strategy'] ?? null]),
                'start_date' => $config['date_range']['from'] ?? null,
                'end_date' => $config['date_range']['to'] ?? null,
                'starting_capital' => (string) ($config['starting_capital'] ?? '100000.00'),
                'min_dte' => $config['dte']['min'] ?? 7,
                'max_dte' => $config['dte']['max'] ?? 45,
                'min_confidence' => (string) ($config['confidence_threshold'] ?? '0.55'),
                'slippage_pct' => (string) ($config['slippage_pct'] ?? '0.02'),
            ]);
            $backtestRun->update(['status' => BacktestStatus::Running, 'started_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Failed to start backtest on the ai-engine', [
                'backtest_run_id' => $backtestRun->id,
                'exception' => $e->getMessage(),
            ]);
            $backtestRun->update(['status' => BacktestStatus::Failed, 'completed_at' => now()]);

            return;
        }

        $response = $this->pollUntilTerminal($client, $backtestRun->id);

        if ($response === null) {
            // Didn't reach completed/failed within the poll budget -- leave
            // status as Running rather than guessing. There's no scheduled
            // reconciliation job to catch this up later (documented gap);
            // in practice the engine finishes well inside this budget.
            Log::warning('Backtest did not reach a terminal state within the poll budget', [
                'backtest_run_id' => $backtestRun->id,
            ]);

            return;
        }

        try {
            $mapper->persist($backtestRun, $response);
        } catch (Throwable $e) {
            Log::warning('Failed to persist backtest result', [
                'backtest_run_id' => $backtestRun->id,
                'exception' => $e->getMessage(),
            ]);
            $backtestRun->update(['status' => BacktestStatus::Failed, 'completed_at' => now()]);
        }
    }

    /**
     * @return array{status: string, result?: array<string, mixed>|null}|null
     */
    private function pollUntilTerminal(AiEngineClient $client, string $backtestId): ?array
    {
        for ($attempt = 0; $attempt < self::MAX_POLL_ATTEMPTS; $attempt++) {
            try {
                $response = $client->getBacktest($backtestId);
            } catch (Throwable $e) {
                Log::warning('Failed to poll backtest status from the ai-engine', [
                    'backtest_run_id' => $backtestId,
                    'exception' => $e->getMessage(),
                ]);

                return null;
            }

            if (in_array($response['status'] ?? null, ['completed', 'failed'], true)) {
                return $response;
            }

            usleep(self::POLL_INTERVAL_MS * 1000);
        }

        return null;
    }
}
