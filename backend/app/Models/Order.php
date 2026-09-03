<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\SubmissionChannel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuids;

    protected $fillable = [
        'trade_proposal_id',
        'broker_order_id',
        'client_order_id',
        'idempotency_key',
        'status',
        'order_class',
        'symbol',
        'qty',
        'submitted_via',
        'raw_request',
        'raw_response',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'qty' => 'integer',
            'submitted_via' => SubmissionChannel::class,
            'raw_request' => 'array',
            'raw_response' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function tradeProposal(): BelongsTo
    {
        return $this->belongsTo(TradeProposal::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(OrderLeg::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
