<?php

namespace App\Models;

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single LangGraph workflow execution. `id` doubles as the run_id shared
 * with the FastAPI ai-engine over the internal signed API.
 */
class AgentRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'trigger',
        'mode',
        'status',
        'trace_id',
        'started_at',
        'completed_at',
        'summary',
        'symbol_universe',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => RunTrigger::class,
            'mode' => RunMode::class,
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'symbol_universe' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AgentLog::class);
    }

    public function marketSnapshots(): HasMany
    {
        return $this->hasMany(MarketSnapshot::class);
    }

    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AiDecision::class);
    }

    public function tradeProposals(): HasMany
    {
        return $this->hasMany(TradeProposal::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class);
    }
}
