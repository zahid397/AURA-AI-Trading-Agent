<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'period_start',
        'period_end',
        'total_return',
        'win_rate',
        'profit_factor',
        'max_drawdown',
        'sharpe_like',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_return' => 'decimal:6',
            'win_rate' => 'decimal:4',
            'profit_factor' => 'decimal:4',
            'max_drawdown' => 'decimal:6',
            'sharpe_like' => 'decimal:4',
            'generated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
