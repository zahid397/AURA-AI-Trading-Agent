<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AgentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentStateRequest extends FormRequest
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
            'enabled' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(array_column(AgentStatus::cases(), 'value'))],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if (! $this->has('enabled') && ! $this->has('status')) {
                    $validator->errors()->add('enabled', 'At least one of "enabled" or "status" must be provided.');
                }
            },
        ];
    }
}
