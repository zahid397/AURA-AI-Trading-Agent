<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PortfolioSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PortfolioSnapshot
 */
class PortfolioSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'snapshot_at' => $this->snapshot_at,
            'equity' => (string) $this->equity,
            'cash' => (string) $this->cash,
            'buying_power' => (string) $this->buying_power,
            'unrealized_pl' => (string) $this->unrealized_pl,
            'realized_pl' => (string) $this->realized_pl,
            'options_exposure' => (string) $this->options_exposure,
        ];
    }
}
