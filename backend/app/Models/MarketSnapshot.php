<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_run_id',
        'symbol',
        'snapshot_at',
        'price',
        'volume',
        'indicators',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_at' => 'datetime',
            'price' => 'decimal:4',
            'volume' => 'integer',
            'indicators' => 'array',
        ];
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
