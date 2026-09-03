<?php

namespace App\Models;

use App\Enums\OptionType;
use App\Enums\OrderSide;
use App\Enums\PositionIntent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyLeg extends Model
{
    use HasUuids;

    protected $fillable = [
        'strategy_id',
        'action',
        'occ_symbol',
        'underlying',
        'option_type',
        'strike',
        'expiration',
        'position_intent',
        'ratio_qty',
        'quote_bid',
        'quote_ask',
        'delta',
        'gamma',
        'theta',
        'vega',
        'open_interest',
        'volume',
        'quote_time',
    ];

    protected function casts(): array
    {
        return [
            'action' => OrderSide::class,
            'option_type' => OptionType::class,
            'strike' => 'decimal:4',
            'expiration' => 'date',
            'position_intent' => PositionIntent::class,
            'ratio_qty' => 'integer',
            'quote_bid' => 'decimal:4',
            'quote_ask' => 'decimal:4',
            'delta' => 'decimal:6',
            'gamma' => 'decimal:6',
            'theta' => 'decimal:6',
            'vega' => 'decimal:6',
            'open_interest' => 'integer',
            'volume' => 'integer',
            'quote_time' => 'datetime',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }
}
