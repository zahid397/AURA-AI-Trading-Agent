<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'strategy_id' => $this->strategy_id,
            'symbol' => $this->symbol,
            'legs' => $this->legs,
            'quantity' => $this->quantity,
            'avg_entry_price' => (string) $this->avg_entry_price,
            'current_value' => (string) $this->current_value,
            'unrealized_pl' => (string) $this->unrealized_pl,
            'status' => $this->status->value,
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
        ];
    }
}
