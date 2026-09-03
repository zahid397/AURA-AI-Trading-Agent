<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned snapshot of risk limits. PATCH /risk/policy must always
 * create a new version row rather than mutating an active one — see
 * App\Http\Controllers\Api\V1\RiskController::updatePolicy().
 */
class RiskPolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'version',
        'name',
        'limits',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'limits' => 'array',
            'active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(RiskEvaluation::class);
    }
}
