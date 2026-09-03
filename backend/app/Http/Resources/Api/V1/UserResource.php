<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'currency_display' => $this->currency_display,
            'default_page' => $this->default_page,
            'settings' => UserSettingsResource::make($this->whenLoaded('settings')),
            'created_at' => $this->created_at,
        ];
    }
}
