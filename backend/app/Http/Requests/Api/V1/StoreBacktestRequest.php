<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\StrategyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBacktestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Matches the shape the Backtest Builder form (frontend `BacktestConfig`
     * type) actually sends -- these rules previously expected flat
     * `start_date`/`end_date`/`starting_equity` fields the frontend never
     * sent (it sends `date_range.from`/`.to` and `starting_capital`
     * instead), so every submission failed validation before reaching the
     * controller.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'strategy' => ['required', 'string', Rule::in(array_column(StrategyType::cases(), 'value'))],
            'symbols' => ['required', 'array', 'min:1'],
            'symbols.*' => ['string'],
            'date_range' => ['required', 'array'],
            'date_range.from' => ['required', 'date'],
            'date_range.to' => ['required', 'date', 'after:date_range.from'],
            'starting_capital' => ['sometimes', 'numeric', 'min:0'],
            'dte' => ['sometimes', 'array'],
            'dte.min' => ['sometimes', 'integer', 'min:0'],
            'dte.max' => ['sometimes', 'integer', 'gte:dte.min'],
            'entry_rules' => ['sometimes', 'nullable', 'string'],
            'exit_rules' => ['sometimes', 'nullable', 'string'],
            'confidence_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'slippage_pct' => ['sometimes', 'numeric', 'min:0'],
            'risk_policy_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
