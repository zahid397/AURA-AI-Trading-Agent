<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateAgentStateRequest;
use App\Http\Resources\Api\V1\AgentLogResource;
use App\Http\Resources\Api\V1\AgentResource;
use App\Models\Agent;
use App\Models\AgentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        return AgentResource::collection(Agent::query()->orderBy('type')->get())->response();
    }

    public function show(Agent $agent): JsonResponse
    {
        return AgentResource::make($agent)->response();
    }

    public function updateState(UpdateAgentStateRequest $request, Agent $agent): JsonResponse
    {
        $agent->update($request->validated());

        return AgentResource::make($agent->fresh())->response();
    }

    public function logs(Request $request, Agent $agent): JsonResponse
    {
        $logs = AgentLog::query()
            ->where('agent_type', $agent->type)
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return AgentLogResource::collection($logs)->response();
    }
}
