<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AlpacaConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safety: intentionally never exposes `secret_ciphertext`. Only a
 * non-secret last-4 fingerprint of the API key and a boolean "configured"
 * flag are returned — enough for the Settings UI, never enough to
 * reconstruct a credential.
 *
 * @mixin AlpacaConnection
 */
class AlpacaConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'environment' => $this->environment->value,
            'account_id' => $this->account_id,
            'api_key_last4' => $this->api_key_last4,
            'configured' => $this->isConfigured(),
            'status' => $this->status->value,
            'capabilities' => $this->capabilities,
            'last_synced_at' => $this->last_synced_at,
        ];
    }
}
