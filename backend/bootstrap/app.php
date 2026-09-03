<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AddRequestId;
use App\Http\Middleware\VerifyInternalSignature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(AddRequestId::class);
        $middleware->alias([
            'internal.signature' => VerifyInternalSignature::class,
        ]);

        // This is a pure JSON API -- there is no named `login` route (see
        // routes/web.php). Without this, Laravel's default Authenticate
        // middleware tries to build a redirect via route('login') for any
        // request it doesn't detect as JSON-expecting (e.g. a plain curl
        // with no Accept header, or an axios call missing one), which
        // throws RouteNotFoundException and surfaces as a raw 500 instead
        // of a clean 401 -- exactly what an unauthenticated/expired-session
        // request from the SPA would hit. Returning null unconditionally
        // means Authenticate::redirectTo() never attempts a redirect and
        // always falls through to throwing AuthenticationException, which
        // the exception handler below already converts to a proper
        // {"error": {"code": "UNAUTHENTICATED", ...}} 401 response.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Consistent JSON error envelope for the whole API: every error
        // response carries a stable machine-readable `error.code` plus the
        // request id (App\Http\Middleware\AddRequestId) so a single failed
        // call can be correlated across client, server logs, and support
        // tickets.
        $exceptions->renderable(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $requestId = $request->attributes->get('request_id');

            [$status, $code, $message, $details] = match (true) {
                $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->details],
                $e instanceof ValidationException => [422, 'VALIDATION_ERROR', 'The given data was invalid.', ['fields' => $e->errors()]],
                $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Authentication is required.', []],
                $e instanceof AuthorizationException => [403, 'FORBIDDEN', $e->getMessage() ?: 'This action is unauthorized.', []],
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', 'The requested resource was not found.', []],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'HTTP_ERROR', $e->getMessage() ?: 'An error occurred.', []],
                default => [500, 'INTERNAL_ERROR', 'An unexpected error occurred.', []],
            };

            if ($status >= 500) {
                // Never surface internal exception messages/traces to
                // clients — log server-side only, keyed by request id.
                Log::error($e->getMessage(), ['request_id' => $requestId, 'exception' => $e]);
                $message = 'An unexpected error occurred.';
            }

            return response()->json([
                'error' => array_filter([
                    'code' => $code,
                    'message' => $message,
                    'details' => $details ?: null,
                ], fn ($v) => $v !== null),
                'request_id' => $requestId,
            ], $status);
        });
    })->create();
