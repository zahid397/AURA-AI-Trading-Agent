<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StrategyResource;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view over the strategies an agent run produced — this is the
 * backend's equivalent of "scan results" / "opportunities": each row is a
 * ranked, scored candidate strategy (see strategies.rank / .score)
 * surfaced by the market_analyst + options_strategy agents. There is no
 * separate option_scans table in the schema; strategies already carries
 * everything a scan-results view needs.
 */
class StrategyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'symbol' => ['sometimes', 'string'],
            'agent_run_id' => ['sometimes', 'uuid'],
            'selected' => ['sometimes', 'boolean'],
        ]);

        $strategies = Strategy::query()
            ->whereHas('agentRun', fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($request->filled('symbol'), fn ($q) => $q->where('symbol', $request->string('symbol')))
            ->when($request->filled('agent_run_id'), fn ($q) => $q->where('agent_run_id', $request->string('agent_run_id')))
            ->when($request->has('selected'), fn ($q) => $q->where('selected', $request->boolean('selected')))
            ->orderByDesc('score')
            ->paginate((int) $request->integer('per_page', 25));

        return StrategyResource::collection($strategies)->response();
    }

    public function show(Request $request, Strategy $strategy): JsonResponse
    {
        if ($strategy->agentRun->user_id !== $request->user()->id) {
            throw new ApiException('FORBIDDEN', 'You do not have access to this strategy.', 403);
        }

        $strategy->load('legs');

        return StrategyResource::make($strategy)->response();
    }
}
