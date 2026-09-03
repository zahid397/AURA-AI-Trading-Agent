<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'backtest_run_id',
        'metrics',
        'equity_curve',
        'drawdown_curve',
        'return_distribution',
        'monthly_returns',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'equity_curve' => 'array',
            'drawdown_curve' => 'array',
            'return_distribution' => 'array',
            'monthly_returns' => 'array',
        ];
    }

    public function backtestRun(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class);
    }
}
