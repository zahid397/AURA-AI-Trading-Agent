<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RiskRuleResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RiskRuleResult
 */
class RiskRuleResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule_key' => $this->rule_key,
            'description' => $this->description,
            'observed_value' => $this->observed_value,
            'limit_value' => $this->limit_value,
            'passed' => $this->passed,
            'severity' => $this->severity->value,
            'explanation' => $this->explanation,
        ];
    }
}
