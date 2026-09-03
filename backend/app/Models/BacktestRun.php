<?php

namespace App\Models;

use App\Enums\BacktestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacktestRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'config',
        'seed_hash',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'status' => BacktestStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(BacktestResult::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(BacktestTrade::class);
    }
}
