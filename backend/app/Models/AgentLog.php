<?php

namespace App\Models;

use App\Enums\AgentType;
use App\Enums\LogLevel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_run_id',
        'agent_type',
        'level',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'agent_type' => AgentType::class,
            'level' => LogLevel::class,
            'context' => 'array',
        ];
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
