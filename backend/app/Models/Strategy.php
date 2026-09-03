<?php

namespace App\Models;

use App\Enums\Direction;
use App\Enums\StrategyType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Strategy extends Model
{
    use HasUuids;

    protected $fillable = [
        // 'id' is intentionally fillable: EventsController assigns it from the
        // ai-engine's own `candidate_id` (see docs/architecture.md) so that a
        // later `risk.evaluated`/`order.submitted` event can reference this
        // exact row without a round trip to discover an auto-generated id.
        // Safe because the only writer is the HMAC-signed internal event
        // pipeline, never user input.
        'id',
        'agent_run_id',
        'symbol',
        'strategy_type',
        'regime',
        'thesis',
        'confidence',
        'net_debit',
        'net_credit',
        'max_profit',
        'max_loss',
        'breakevens',
        'probability_of_profit',
        'liquidity_score',
        'score',
        'score_breakdown',
        'quote_timestamp',
        'dte',
        'rank',
        'selected',
    ];

    protected function casts(): array
    {
        return [
            'strategy_type' => StrategyType::class,
            'regime' => Direction::class,
            'confidence' => 'decimal:4',
            'net_debit' => 'decimal:4',
            'net_credit' => 'decimal:4',
            'max_profit' => 'decimal:4',
            'max_loss' => 'decimal:4',
            'breakevens' => 'array',
            'probability_of_profit' => 'decimal:4',
            'liquidity_score' => 'decimal:4',
            'score' => 'decimal:4',
            'score_breakdown' => 'array',
            'quote_timestamp' => 'datetime',
            'dte' => 'integer',
            'rank' => 'integer',
            'selected' => 'boolean',
        ];
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(StrategyLeg::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AiDecision::class);
    }

    public function tradeProposals(): HasMany
    {
        return $this->hasMany(TradeProposal::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
