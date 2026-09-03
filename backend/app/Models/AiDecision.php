<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDecision extends Model
{
    use HasUuids;

    protected $table = 'ai_decisions';

    protected $fillable = [
        'agent_run_id',
        'strategy_id',
        'thesis',
        'evidence',
        'confidence',
        'model_provider',
        'model_name',
        'prompt_version',
        'feature_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'confidence' => 'decimal:4',
            'feature_snapshot' => 'array',
        ];
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }
}
