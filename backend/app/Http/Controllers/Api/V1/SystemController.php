<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AiEngine\AiEngineClient;
use App\Services\AiEngine\AiEngineException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        $dbOk = true;

        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        return response()->json([
            'data' => [
                'status' => $dbOk ? 'ok' : 'degraded',
                'database' => $dbOk,
                'trading_env' => config('aura.trading_env'),
                'allow_live_trading' => config('aura.allow_live_trading'),
                'time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function capabilities(AiEngineClient $client): JsonResponse
    {
        try {
            $engineCapabilities = $client->capabilities();
            $engineReachable = true;
        } catch (AiEngineException) {
            $engineCapabilities = [];
            $engineReachable = false;
        }

        return response()->json([
            'data' => [
                'trading_env' => config('aura.trading_env'),
                'alpaca_base_url' => config('aura.alpaca.base_url'),
                'ai_engine_reachable' => $engineReachable,
                'ai_engine_capabilities' => $engineCapabilities,
            ],
        ]);
    }
}
