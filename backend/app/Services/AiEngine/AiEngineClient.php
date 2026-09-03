<?php

namespace App\Services\AiEngine;

use App\Http\Middleware\VerifyInternalSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Laravel -> FastAPI half of the signed internal API described in
 * docs/architecture.md ("Internal API contract (Laravel <-> FastAPI)").
 *
 * Every request is signed with the same HMAC-SHA256 scheme the
 * ai-engine uses to call Laravel back (see
 * App\Http\Middleware\VerifyInternalSignature): the raw JSON body is
 * built ourselves and sent verbatim via withBody() so the bytes that are
 * signed are exactly the bytes that go over the wire — no risk of a
 * signature mismatch from re-serialization.
 */
class AiEngineClient
{
    public function startRun(array $payload): array
    {
        return $this->send('post', '/internal/v1/runs', $payload, $this->timeout());
    }

    public function getRun(string $runId): array
    {
        return $this->send('get', "/internal/v1/runs/{$runId}", [], $this->timeout());
    }

    public function cancelRun(string $runId): array
    {
        return $this->send('post', "/internal/v1/runs/{$runId}/cancel", [], $this->timeout());
    }

    /**
     * @param  array{decision: string, approved_by: string, notes?: string}  $payload
     */
    public function resumeRun(string $runId, array $payload): array
    {
        return $this->send('post', "/internal/v1/runs/{$runId}/resume", $payload, $this->timeout());
    }

    /**
     * Alpaca API/MCP/CLI capability snapshot for Settings/System Health.
     * Sync with a strict short timeout — never let this hang a page load.
     */
    public function capabilities(): array
    {
        return $this->send('get', '/internal/v1/capabilities', [], $this->capabilitiesTimeout());
    }

    public function startBacktest(array $payload): array
    {
        return $this->send('post', '/internal/v1/backtests', $payload, $this->timeout());
    }

    public function getBacktest(string $backtestId): array
    {
        return $this->send('get', "/internal/v1/backtests/{$backtestId}", [], $this->timeout());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $method, string $path, array $payload, int $timeoutSeconds): array
    {
        $rawBody = $method === 'get' ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
        [$timestamp, $signature] = $this->sign($rawBody);

        $request = $this->client($timeoutSeconds)->withHeaders([
            VerifyInternalSignature::TIMESTAMP_HEADER => $timestamp,
            VerifyInternalSignature::SIGNATURE_HEADER => $signature,
        ]);

        if ($method !== 'get') {
            $request = $request->withBody($rawBody, 'application/json');
        }

        try {
            /** @var Response $response */
            $response = $request->{$method}($path);
        } catch (ConnectionException $e) {
            throw AiEngineException::unreachable($e->getMessage());
        }

        if ($response->failed()) {
            throw AiEngineException::errorResponse($response->status(), $response->body());
        }

        return $response->json() ?? [];
    }

    private function client(int $timeoutSeconds): PendingRequest
    {
        return Http::baseUrl((string) config('aura.internal.ai_engine_base_url'))
            ->timeout($timeoutSeconds)
            ->acceptJson();
    }

    /**
     * @return array{0: string, 1: string} [timestamp, signature]
     */
    private function sign(string $rawBody): array
    {
        $secret = (string) config('aura.internal.signing_secret');
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return [$timestamp, $signature];
    }

    private function timeout(): int
    {
        return (int) config('aura.internal.request_timeout_seconds', 10);
    }

    private function capabilitiesTimeout(): int
    {
        return (int) config('aura.internal.capabilities_timeout_seconds', 3);
    }
}
