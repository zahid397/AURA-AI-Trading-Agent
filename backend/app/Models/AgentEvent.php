<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Idempotency ledger + audit trail for every event received from the
 * FastAPI ai-engine at POST /api/internal/v1/events. `event_id` is unique
 * so replays can be detected and treated as no-ops.
 */
class AgentEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'agent_run_id',
        'type',
        'payload',
        'signature_valid',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
