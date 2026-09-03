<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the signed internal API used by the FastAPI ai-engine to
 * call Laravel back (docs/architecture.md: "Internal API contract").
 *
 * Scheme (Stripe-webhook style, shared with the ai-engine side):
 *   signed_payload = "{X-Aura-Timestamp}.{raw request body}"
 *   X-Aura-Signature = hex(hmac_sha256(signed_payload, AURA_INTERNAL_SIGNING_SECRET))
 *
 * Requests older than `aura.internal.timestamp_skew_seconds` (default 300s)
 * are rejected to limit replay-attack windows; the `event_id` uniqueness
 * check (see EventsController) is the actual idempotency guard.
 */
class VerifyInternalSignature
{
    public const TIMESTAMP_HEADER = 'X-Aura-Timestamp';

    public const SIGNATURE_HEADER = 'X-Aura-Signature';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('aura.internal.signing_secret');

        if ($secret === '') {
            throw new ApiException('INTERNAL_SIGNING_NOT_CONFIGURED', 'Internal signing secret is not configured.', 500);
        }

        $timestampHeader = $request->header(self::TIMESTAMP_HEADER);
        $signatureHeader = $request->header(self::SIGNATURE_HEADER);

        if (blank($timestampHeader) || blank($signatureHeader)) {
            throw new ApiException('MISSING_SIGNATURE', 'Missing internal signature headers.', 401);
        }

        if (! ctype_digit((string) $timestampHeader)) {
            throw new ApiException('INVALID_TIMESTAMP', 'Timestamp header must be a unix timestamp.', 401);
        }

        $timestamp = (int) $timestampHeader;
        $skew = (int) config('aura.internal.timestamp_skew_seconds', 300);

        if (abs(time() - $timestamp) > $skew) {
            throw new ApiException('STALE_TIMESTAMP', 'Request timestamp is outside the allowed skew window.', 401);
        }

        $rawBody = $request->getContent();
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        if (! hash_equals($expected, (string) $signatureHeader)) {
            throw new ApiException('INVALID_SIGNATURE', 'Invalid internal request signature.', 401);
        }

        return $next($request);
    }
}
