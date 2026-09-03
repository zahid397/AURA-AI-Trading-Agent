<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAgentRunRequest;
use App\Http\Resources\Api\V1\AgentRunResource;
use App\Jobs\StartAgentRun;
use App\Models\AgentRun;
use App\Services\AiEngine\AiEngineClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AgentRunController extends Controller
{
    public function store(StoreAgentRunRequest $request): JsonResponse
    {
        $user = $request->user();
        $mode = $request->validated('mode') ?? $user->settings?->mode?->value ?? RunMode::ApprovalRequired->value;

        $agentRun = AgentRun::query()->create([
            'user_id' => $user->id,
            'trigger' => RunTrigger::Manual,
            'mode' => $mode,
            'status' => RunStatus::Pending,
            'trace_id' => (string) Str::uuid(),
            'symbol_universe' => $request->validated('symbols'),
        ]);

        StartAgentRun::dispatch($agentRun->id);

        return AgentRunResource::make($agentRun)->response()->setStatusCode(202);
    }

    public function show(Request $request, AgentRun $agentRun): JsonResponse
    {
        $this->authorizeOwnership($request, $agentRun);

        $agentRun->load(['logs', 'strategies.legs']);

        return AgentRunResource::make($agentRun)->response();
    }

    public function cancel(Request $request, AgentRun $agentRun): JsonResponse
    {
        $this->authorizeOwnership($request, $agentRun);

        if (in_array($agentRun->status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::Rejected], true)) {
            throw new ApiException('RUN_NOT_CANCELLABLE', "Run is already in a terminal state ({$agentRun->status->value}).", 409);
        }

        $agentRun->update([
            'status' => RunStatus::Cancelled,
            'completed_at' => now(),
        ]);

        // Best-effort cooperative cancellation upstream; the local status
        // change above is authoritative regardless of ai-engine
        // reachability (Laravel is the system of record).
        try {
            app(AiEngineClient::class)->cancelRun($agentRun->id);
        } catch (\Throwable $e) {
            report($e);
        }

        return AgentRunResource::make($agentRun->fresh())->response();
    }

    private function authorizeOwnership(Request $request, AgentRun $agentRun): void
    {
        if ($agentRun->user_id !== $request->user()->id) {
            throw new ApiException('FORBIDDEN', 'You do not have access to this agent run.', 403);
        }
    }
}
