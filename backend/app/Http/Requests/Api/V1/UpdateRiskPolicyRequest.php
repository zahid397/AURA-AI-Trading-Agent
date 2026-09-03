<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRiskPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'limits' => ['required', 'array'],
            'limits.max_daily_loss_pct' => ['required', 'numeric', 'min:0'],
            'limits.max_drawdown_pct' => ['required', 'numeric', 'min:0'],
            'limits.max_position_risk_pct' => ['required', 'numeric', 'min:0'],
            'limits.max_total_exposure_pct' => ['required', 'numeric', 'min:0'],
            'limits.max_symbol_exposure_pct' => ['required', 'numeric', 'min:0'],
            'limits.min_confidence' => ['required', 'numeric', 'between:0,1'],
            'limits.max_quote_age_seconds' => ['required', 'integer', 'min:0'],
            'limits.min_open_interest' => ['required', 'integer', 'min:0'],
            'limits.max_spread_pct' => ['required', 'numeric', 'min:0'],
            'limits.max_open_positions' => ['required', 'integer', 'min:0'],
        ];
    }
}
