<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StrategyLeg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StrategyLeg
 */
class StrategyLegResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'occ_symbol' => $this->occ_symbol,
            'underlying' => $this->underlying,
            'option_type' => $this->option_type->value,
            'strike' => (string) $this->strike,
            'expiration' => $this->expiration?->toDateString(),
            'position_intent' => $this->position_intent->value,
            'ratio_qty' => $this->ratio_qty,
            'quote_bid' => (string) $this->quote_bid,
            'quote_ask' => (string) $this->quote_ask,
            'delta' => $this->delta !== null ? (string) $this->delta : null,
            'gamma' => $this->gamma !== null ? (string) $this->gamma : null,
            'theta' => $this->theta !== null ? (string) $this->theta : null,
            'vega' => $this->vega !== null ? (string) $this->vega : null,
            'open_interest' => $this->open_interest,
            'volume' => $this->volume,
            'quote_time' => $this->quote_time,
        ];
    }
}
