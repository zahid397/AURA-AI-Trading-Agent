<?php

namespace App\Services\AiEngine;

use App\Exceptions\ApiException;

/**
 * Raised whenever the FastAPI ai-engine cannot be reached or returns an
 * error. Always renders as a stable `error.code` via the JSON error
 * envelope (bootstrap/app.php) — never leaks internal engine details.
 */
class AiEngineException extends ApiException
{
    public static function unreachable(string $reason): self
    {
        return new self('AI_ENGINE_UNREACHABLE', "Could not reach the AI engine: {$reason}", 503);
    }

    public static function errorResponse(int $status, string $body): self
    {
        return new self('AI_ENGINE_ERROR', 'The AI engine returned an error response.', 502, ['upstream_status' => $status, 'upstream_body' => mb_substr($body, 0, 2000)]);
    }
}
