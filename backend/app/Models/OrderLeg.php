<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\PositionIntent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLeg extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'occ_symbol',
        'side',
        'ratio_qty',
        'position_intent',
        'filled_qty',
        'filled_avg_price',
    ];

    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'ratio_qty' => 'integer',
            'position_intent' => PositionIntent::class,
            'filled_qty' => 'integer',
            'filled_avg_price' => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
