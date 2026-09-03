<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BacktestRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BacktestRun
 */
class BacktestRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'config' => $this->config,
            'seed_hash' => $this->seed_hash,
            'status' => $this->status->value,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            // The frontend's BacktestDetail expects a single `result` object
            // (or null), not the `results` array the `results` hasMany
            // relation name suggests -- a run only ever produces one.
            'result' => $this->whenLoaded('results', fn () => $this->results->isNotEmpty() ? BacktestResultResource::make($this->results->first()) : null),
            'trades' => BacktestTradeResource::collection($this->whenLoaded('trades')),
        ];
    }
}
