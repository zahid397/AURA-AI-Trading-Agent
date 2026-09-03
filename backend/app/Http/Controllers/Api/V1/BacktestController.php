<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BacktestStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBacktestRequest;
use App\Http\Resources\Api\V1\BacktestRunResource;
use App\Jobs\StartBacktest;
use App\Models\BacktestRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BacktestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $runs = BacktestRun::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 15));

        return BacktestRunResource::collection($runs)->response();
    }

    public function store(StoreBacktestRequest $request): JsonResponse
    {
        $user = $request->user();
        $config = $request->validated();

        $backtestRun = BacktestRun::query()->create([
            'user_id' => $user->id,
            'config' => $config,
            'seed_hash' => hash('sha256', json_encode($config).$user->id.now()->toIso8601String()),
            'status' => BacktestStatus::Pending,
        ]);

        StartBacktest::dispatch($backtestRun->id);

        return BacktestRunResource::make($backtestRun)->response()->setStatusCode(202);
    }

    public function show(Request $request, BacktestRun $backtestRun): JsonResponse
    {
        $this->authorizeOwnership($request, $backtestRun);

        $backtestRun->load(['results', 'trades']);

        return BacktestRunResource::make($backtestRun)->response();
    }

    private function authorizeOwnership(Request $request, BacktestRun $backtestRun): void
    {
        if ($backtestRun->user_id !== $request->user()->id) {
            throw new ApiException('FORBIDDEN', 'You do not have access to this backtest run.', 403);
        }
    }
}
