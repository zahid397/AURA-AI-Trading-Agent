<?php

namespace App\Models;

use App\Enums\PositionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Position extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'strategy_id',
        'symbol',
        'legs',
        'quantity',
        'avg_entry_price',
        'current_value',
        'unrealized_pl',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'legs' => 'array',
            'quantity' => 'integer',
            'avg_entry_price' => 'decimal:4',
            'current_value' => 'decimal:4',
            'unrealized_pl' => 'decimal:4',
            'status' => PositionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }
}
