<?php

namespace App\Models;

use App\Enums\AlpacaConnectionStatus;
use App\Enums\AlpacaEnvironment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's Alpaca paper-trading credentials.
 *
 * Safety: `secret_ciphertext` must NEVER be exposed via toArray(), JSON
 * serialization, logs, or exceptions. It is hidden here as a hard backstop;
 * API responses must go through AlpacaConnectionResource, which only ever
 * exposes `api_key_last4` (already non-secret) and a boolean `configured`
 * flag — never the ciphertext itself.
 */
class AlpacaConnection extends Model
{
    use HasUuids;

    protected $table = 'alpaca_connections';

    protected $fillable = [
        'user_id',
        'environment',
        'account_id',
        'api_key_last4',
        'secret_ciphertext',
        'status',
        'capabilities',
        'last_synced_at',
    ];

    /**
     * Hard backstop: never serialize the secret, even if a caller forgets
     * to route through the API Resource.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'secret_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'environment' => AlpacaEnvironment::class,
            'status' => AlpacaConnectionStatus::class,
            'capabilities' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfigured(): bool
    {
        return filled($this->secret_ciphertext);
    }
}
