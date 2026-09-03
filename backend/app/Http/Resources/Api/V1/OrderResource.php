<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trade_proposal_id' => $this->trade_proposal_id,
            'broker_order_id' => $this->broker_order_id,
            'client_order_id' => $this->client_order_id,
            'status' => $this->status->value,
            'order_class' => $this->order_class,
            'symbol' => $this->symbol,
            'qty' => $this->qty,
            'submitted_via' => $this->submitted_via->value,
            'submitted_at' => $this->submitted_at,
            'created_at' => $this->created_at,
            'legs' => OrderLegResource::collection($this->whenLoaded('legs')),
        ];
    }
}
