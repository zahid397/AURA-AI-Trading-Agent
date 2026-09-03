<?php

namespace App\Models;

use App\Enums\TradeProposalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeProposal extends Model
{
    use HasUuids;

    protected $fillable = [
        // 'id' is intentionally fillable: EventsController assigns it from the
        // ai-engine's own `evaluation_id` so `order.submitted` can reference
        // this exact proposal later. Safe -- only the HMAC-signed internal
        // event pipeline writes here, never user input.
        'id',
        'agent_run_id',
        'strategy_id',
        'user_id',
        'status',
        'risk_evaluation_id',
        'expires_at',
        'approved_at',
        'approved_by',
        'rejected_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => TradeProposalStatus::class,
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The evaluation currently referenced by risk_evaluation_id — a soft
     * pointer (no DB FK on this side; see migration comment) to whichever
     * evaluation is considered authoritative for this proposal right now.
     */
    public function riskEvaluation(): BelongsTo
    {
        return $this->belongsTo(RiskEvaluation::class, 'risk_evaluation_id');
    }

    /**
     * Full evaluation history for this proposal (a proposal can be
     * re-evaluated, e.g. after a risk policy version change).
     */
    public function riskEvaluations(): HasMany
    {
        return $this->hasMany(RiskEvaluation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
