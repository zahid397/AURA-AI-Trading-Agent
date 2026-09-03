<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AiDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiDecision
 */
class DecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_run_id' => $this->agent_run_id,
            'strategy_id' => $this->strategy_id,
            'thesis' => $this->thesis,
            'evidence' => $this->evidence,
            'confidence' => (string) $this->confidence,
            'model_provider' => $this->model_provider,
            'model_name' => $this->model_name,
            'prompt_version' => $this->prompt_version,
            'created_at' => $this->created_at,
            'strategy' => StrategyResource::make($this->whenLoaded('strategy')),
        ];
    }
}
