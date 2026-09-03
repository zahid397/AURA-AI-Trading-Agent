<?php

namespace App\Models;

use App\Enums\RiskSeverity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskRuleResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'risk_evaluation_id',
        'rule_key',
        'description',
        'observed_value',
        'limit_value',
        'passed',
        'severity',
        'explanation',
    ];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'severity' => RiskSeverity::class,
        ];
    }

    public function riskEvaluation(): BelongsTo
    {
        return $this->belongsTo(RiskEvaluation::class);
    }
}
