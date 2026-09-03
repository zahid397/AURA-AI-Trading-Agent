<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates (or propagates) a request id for every request so API clients
 * and server logs can correlate a single call end-to-end. Downstream code
 * reads it via `$request->attributes->get('request_id')` — the JSON error
 * envelope (bootstrap/app.php) always includes it.
 */
class AddRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header(self::HEADER) ?: (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
