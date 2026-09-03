<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteTradeProposalRequest extends FormRequest
{
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

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
            'qty' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        return $this->header(self::IDEMPOTENCY_HEADER);
    }
}
