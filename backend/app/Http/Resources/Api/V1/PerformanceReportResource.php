<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PerformanceReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PerformanceReport
 */
class PerformanceReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'total_return' => (string) $this->total_return,
            'win_rate' => (string) $this->win_rate,
            'profit_factor' => (string) $this->profit_factor,
            'max_drawdown' => (string) $this->max_drawdown,
            'sharpe_like' => $this->sharpe_like !== null ? (string) $this->sharpe_like : null,
            'generated_at' => $this->generated_at,
        ];
    }
}
