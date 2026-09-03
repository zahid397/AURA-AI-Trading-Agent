<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Services\AiEngine\AiEngineClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the FastAPI ai-engine that a new run should start
 * (POST /internal/v1/runs, per docs/architecture.md). Queued rather than
 * called synchronously from the controller so a slow/unreachable ai-engine
 * never blocks the HTTP response to the SPA — the run row already exists
 * with status=pending and the frontend polls /agent-runs/{id} (or, once
 * Reverb is wired, listens for run.started) to observe progress.
 */
class StartAgentRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $agentRunId)
    {
        //
    }

    public function handle(AiEngineClient $client): void
    {
        $agentRun = AgentRun::query()->find($this->agentRunId);

        if (! $agentRun) {
            return;
        }

        try {
            $client->startRun([
                'run_id' => $agentRun->id,
                'user_id' => $agentRun->user_id,
                'mode' => $agentRun->mode->value,
                'trigger' => $agentRun->trigger->value,
                'symbols' => $agentRun->symbol_universe ?? [],
                'trace_id' => $agentRun->trace_id,
            ]);
        } catch (Throwable $e) {
            // The ai-engine may legitimately be offline (e.g. local dev
            // without the FastAPI service running, or this hackathon
            // sandbox). Record the failure but leave the run as `pending`
            // rather than throwing — a human can retry via the UI, and no
            // partial domain state was written.
            Log::warning('Failed to start agent run on the ai-engine', [
                'agent_run_id' => $agentRun->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
