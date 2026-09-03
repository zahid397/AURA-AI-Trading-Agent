<?php

namespace App\Models;

use App\Enums\RiskResult;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskEvaluation extends Model
{
    use HasUuids;

    protected $fillable = [
        'trade_proposal_id',
        'risk_policy_id',
        'risk_policy_version',
        'result',
        'risk_score',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'risk_policy_version' => 'integer',
            'result' => RiskResult::class,
            'risk_score' => 'decimal:4',
            'evaluated_at' => 'datetime',
        ];
    }

    public function tradeProposal(): BelongsTo
    {
        return $this->belongsTo(TradeProposal::class);
    }

    public function riskPolicy(): BelongsTo
    {
        return $this->belongsTo(RiskPolicy::class);
    }

    public function ruleResults(): HasMany
    {
        return $this->hasMany(RiskRuleResult::class);
    }
}
