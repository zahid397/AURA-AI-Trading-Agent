<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RiskEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RiskEvaluation
 */
class RiskEvaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trade_proposal_id' => $this->trade_proposal_id,
            'risk_policy_id' => $this->risk_policy_id,
            'risk_policy_version' => $this->risk_policy_version,
            'result' => $this->result->value,
            'risk_score' => (string) $this->risk_score,
            'evaluated_at' => $this->evaluated_at,
            'rule_results' => RiskRuleResultResource::collection($this->whenLoaded('ruleResults')),
        ];
    }
}
