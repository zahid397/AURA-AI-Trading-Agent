<?php

namespace App\Http\Resources\Api\V1;

use App\Models\UserSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserSettings
 */
class UserSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'mode' => $this->mode?->value,
            'confidence_threshold' => (string) $this->confidence_threshold,
            'notification_prefs' => $this->notification_prefs,
        ];
    }
}
