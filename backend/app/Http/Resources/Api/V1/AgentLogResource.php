<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AgentLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentLog
 */
class AgentLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_run_id' => $this->agent_run_id,
            'agent_type' => $this->agent_type->value,
            'level' => $this->level->value,
            'message' => $this->message,
            'context' => $this->context,
            'created_at' => $this->created_at,
        ];
    }
}
