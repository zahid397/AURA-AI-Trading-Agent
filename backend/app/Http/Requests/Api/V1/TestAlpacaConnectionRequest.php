<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class TestAlpacaConnectionRequest extends FormRequest
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
            // Both optional: when omitted, the previously stored (encrypted)
            // credential is re-tested instead of replaced.
            'api_key' => ['sometimes', 'string'],
            'api_secret' => ['sometimes', 'string'],
        ];
    }
}
