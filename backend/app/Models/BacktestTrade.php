<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestTrade extends Model
{
    use HasUuids;

    protected $fillable = [
        'backtest_run_id',
        'symbol',
        'entry_at',
        'exit_at',
        'legs',
        'pnl',
        'exit_reason',
    ];

    protected function casts(): array
    {
        return [
            'entry_at' => 'datetime',
            'exit_at' => 'datetime',
            'legs' => 'array',
            'pnl' => 'decimal:4',
        ];
    }

    public function backtestRun(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class);
    }
}
