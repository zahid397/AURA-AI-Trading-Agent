<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BacktestResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BacktestResult
 */
class BacktestResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'backtest_run_id' => $this->backtest_run_id,
            'metrics' => $this->metrics,
            'equity_curve' => $this->equity_curve,
            'drawdown_curve' => $this->drawdown_curve,
            'return_distribution' => $this->return_distribution,
            'monthly_returns' => $this->monthly_returns,
        ];
    }
}
