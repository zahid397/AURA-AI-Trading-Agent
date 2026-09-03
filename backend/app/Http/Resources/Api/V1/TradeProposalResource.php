<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TradeProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TradeProposal
 */
class TradeProposalResource extends JsonResource
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
            'user_id' => $this->user_id,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'rejected_reason' => $this->rejected_reason,
            'created_at' => $this->created_at,
            'strategy' => StrategyResource::make($this->whenLoaded('strategy')),
            'risk_evaluation' => RiskEvaluationResource::make($this->whenLoaded('riskEvaluation')),
            'orders' => OrderResource::collection($this->whenLoaded('orders')),
        ];
    }
}
