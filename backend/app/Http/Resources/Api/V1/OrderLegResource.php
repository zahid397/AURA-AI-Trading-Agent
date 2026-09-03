<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrderLeg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderLeg
 */
class OrderLegResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occ_symbol' => $this->occ_symbol,
            'side' => $this->side->value,
            'ratio_qty' => $this->ratio_qty,
            'position_intent' => $this->position_intent->value,
            'filled_qty' => $this->filled_qty,
            'filled_avg_price' => $this->filled_avg_price !== null ? (string) $this->filled_avg_price : null,
        ];
    }
}
