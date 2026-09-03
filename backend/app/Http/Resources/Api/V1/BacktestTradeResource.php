<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BacktestTrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BacktestTrade
 */
class BacktestTradeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'backtest_run_id' => $this->backtest_run_id,
            'symbol' => $this->symbol,
            'entry_at' => $this->entry_at,
            'exit_at' => $this->exit_at,
            'legs' => $this->legs,
            'pnl' => (string) $this->pnl,
            'exit_reason' => $this->exit_reason,
        ];
    }
}
