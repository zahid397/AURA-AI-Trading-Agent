<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Strategy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Strategy
 */
class StrategyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_run_id' => $this->agent_run_id,
            'symbol' => $this->symbol,
            'strategy_type' => $this->strategy_type->value,
            'regime' => $this->regime->value,
            'thesis' => $this->thesis,
            'confidence' => (string) $this->confidence,
            'net_debit' => $this->net_debit !== null ? (string) $this->net_debit : null,
            'net_credit' => $this->net_credit !== null ? (string) $this->net_credit : null,
            'max_profit' => (string) $this->max_profit,
            'max_loss' => (string) $this->max_loss,
            'breakevens' => $this->breakevens,
            'probability_of_profit' => (string) $this->probability_of_profit,
            'liquidity_score' => (string) $this->liquidity_score,
            'score' => (string) $this->score,
            'score_breakdown' => $this->score_breakdown,
            'quote_timestamp' => $this->quote_timestamp,
            'dte' => $this->dte,
            'rank' => $this->rank,
            'selected' => $this->selected,
            'legs' => StrategyLegResource::collection($this->whenLoaded('legs')),
        ];
    }
}
