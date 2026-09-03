<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AgentRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentRun
 */
class AgentRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'trigger' => $this->trigger->value,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'trace_id' => $this->trace_id,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'summary' => $this->summary,
            'symbol_universe' => $this->symbol_universe,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'logs' => AgentLogResource::collection($this->whenLoaded('logs')),
            'strategies' => StrategyResource::collection($this->whenLoaded('strategies')),
        ];
    }
}
