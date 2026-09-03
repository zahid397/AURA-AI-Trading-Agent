<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A domain-level API error with a stable machine-readable code. Caught by
 * the JSON error envelope renderer in bootstrap/app.php and turned into
 * `{"error": {"code", "message", "details"}, "request_id"}`.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
