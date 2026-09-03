<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'snapshot_at',
        'equity',
        'cash',
        'buying_power',
        'unrealized_pl',
        'realized_pl',
        'options_exposure',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_at' => 'datetime',
            'equity' => 'decimal:4',
            'cash' => 'decimal:4',
            'buying_power' => 'decimal:4',
            'unrealized_pl' => 'decimal:4',
            'realized_pl' => 'decimal:4',
            'options_exposure' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
