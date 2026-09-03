<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AlpacaConnectionStatus;
use App\Enums\AlpacaEnvironment;
use App\Enums\RunMode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TestAlpacaConnectionRequest;
use App\Http\Requests\Api\V1\UpdateSettingsRequest;
use App\Http\Resources\Api\V1\AlpacaConnectionResource;
use App\Http\Resources\Api\V1\UserSettingsResource;
use App\Services\AiEngine\AiEngineClient;
use App\Services\AiEngine\AiEngineException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings ?? $user->settings()->create(['mode' => RunMode::ApprovalRequired]);
        $connection = $user->alpacaConnection;

        return response()->json([
            'data' => [
                'settings' => UserSettingsResource::make($settings),
                'alpaca_connection' => $connection ? AlpacaConnectionResource::make($connection) : null,
            ],
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings ?? $user->settings()->make();
        $settings->fill($request->validated());

        if (! $settings->exists) {
            $settings->user_id = $user->id;
        }

        $settings->save();

        return UserSettingsResource::make($settings)->response();
    }

    /**
     * Tests Alpaca paper-API connectivity. Never logs or returns the raw
     * secret: if new credentials are supplied they are validated against
     * Alpaca's paper endpoint, then persisted only as an encrypted
     * ciphertext (Crypt::encryptString) plus a non-secret last-4
     * fingerprint of the key id. The response always goes through
     * AlpacaConnectionResource, which cannot expose the ciphertext.
     */
    public function testAlpaca(TestAlpacaConnectionRequest $request): JsonResponse
    {
        $user = $request->user();
        $connection = $user->alpacaConnection ?? $user->alpacaConnection()->make([
            'environment' => AlpacaEnvironment::Paper,
            'status' => AlpacaConnectionStatus::NotConfigured,
        ]);

        $apiKey = $request->validated('api_key');
        $apiSecret = $request->validated('api_secret');

        if ($apiKey === null || $apiSecret === null) {
            if (! $connection->exists || ! $connection->isConfigured()) {
                throw new ApiException('ALPACA_NOT_CONFIGURED', 'No stored Alpaca credentials to test; supply api_key and api_secret.', 422);
            }

            $apiKey = 'stored-key-'.$connection->api_key_last4;
            $apiSecret = Crypt::decryptString($connection->secret_ciphertext);
        }

        $baseUrl = (string) config('aura.alpaca.base_url');
        $reachable = false;
        $accountId = $connection->account_id;

        try {
            $response = Http::withHeaders([
                'APCA-API-KEY-ID' => $apiKey,
                'APCA-API-SECRET-KEY' => $apiSecret,
            ])->timeout(5)->get($baseUrl.'/v2/account');

            $reachable = $response->successful();
            $accountId = $reachable ? ($response->json('id') ?? $connection->account_id) : $connection->account_id;
        } catch (\Throwable) {
            // Deliberately catches every throwable, not just
            // ConnectionException: $apiSecret is a local variable in this
            // scope, and PHP's default exception trace string includes
            // scalar call-argument values, so letting anything escape
            // here risks the secret reaching the global exception
            // handler's log line. Treat any failure the same way — as
            // "unreachable" — rather than let a stray exception surface
            // with a potentially secret-bearing stack trace.
        }

        if ($request->filled('api_key') && $request->filled('api_secret')) {
            $connection->api_key_last4 = mb_substr($request->string('api_key'), -4);
            $connection->secret_ciphertext = Crypt::encryptString($request->string('api_secret')->toString());
        }

        $connection->account_id = $accountId ?? $connection->account_id;
        $connection->status = $reachable ? AlpacaConnectionStatus::Connected : AlpacaConnectionStatus::Error;
        $connection->last_synced_at = now();

        if (! $connection->exists) {
            $connection->user_id = $user->id;
        }

        $connection->save();

        return response()->json([
            'data' => [
                'reachable' => $reachable,
                'connection' => AlpacaConnectionResource::make($connection),
            ],
        ]);
    }

    public function testAiProvider(AiEngineClient $client): JsonResponse
    {
        try {
            $capabilities = $client->capabilities();

            return response()->json(['data' => ['reachable' => true, 'capabilities' => $capabilities]]);
        } catch (AiEngineException $e) {
            return response()->json(['data' => ['reachable' => false, 'error' => $e->errorCode]]);
        }
    }
}
