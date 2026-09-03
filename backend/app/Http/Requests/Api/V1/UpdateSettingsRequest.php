<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\RunMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'string', Rule::in(array_column(RunMode::cases(), 'value'))],
            'confidence_threshold' => ['sometimes', 'numeric', 'between:0,1'],
            'notification_prefs' => ['sometimes', 'array'],
        ];
    }
}
