<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PerformanceReportResource;
use App\Http\Resources\Api\V1\PortfolioSnapshotResource;
use App\Http\Resources\Api\V1\PositionResource;
use App\Models\PerformanceReport;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortfolioController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $latest = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->latest('snapshot_at')
            ->first();

        $first = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->oldest('snapshot_at')
            ->first();

        $startingEquity = $first?->equity;
        $totalPl = ($latest && $startingEquity !== null)
            ? bcsub((string) $latest->equity, (string) $startingEquity, 4)
            : null;

        return response()->json([
            // The frontend's PortfolioSummary type reads equity/cash/buying_power/
            // unrealized_pl/realized_pl/options_exposure flat -- nesting them
            // under a `latest` key (as this previously did) left Portfolio's four
            // top KPI cards permanently blank even when real snapshot data
            // existed. `$latest === null` (a brand-new account has no snapshots
            // yet) still returns `data: null` cleanly rather than crashing.
            'data' => $latest !== null
                ? array_merge(
                    PortfolioSnapshotResource::make($latest)->resolve($request),
                    [
                        'starting_equity' => $startingEquity !== null ? (string) $startingEquity : null,
                        'total_pl' => $totalPl,
                    ],
                )
                : null,
        ]);
    }

    public function positions(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['open', 'closed'])],
        ]);

        $positions = Position::query()
            ->where('user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when(! $request->filled('status'), fn ($q) => $q->where('status', 'open'))
            ->latest('opened_at')
            ->paginate((int) $request->integer('per_page', 25));

        return PositionResource::collection($positions)->response();
    }

    public function allocation(Request $request): JsonResponse
    {
        $positions = Position::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->get(['symbol', 'current_value']);

        $totalValue = $positions->reduce(fn (?string $carry, Position $p) => bcadd($carry ?? '0', (string) $p->current_value, 4), '0');

        $bySymbol = $positions->groupBy('symbol')->map(function ($group, $symbol) use ($totalValue) {
            $value = $group->reduce(fn (?string $carry, Position $p) => bcadd($carry ?? '0', (string) $p->current_value, 4), '0');
            $pct = bccomp($totalValue, '0', 4) === 0 ? '0.0000' : bcdiv($value, $totalValue, 4);

            return [
                'symbol' => $symbol,
                'value' => $value,
                'weight' => $pct,
            ];
        })->values();

        return response()->json([
            'data' => [
                'total_value' => $totalValue,
                'by_symbol' => $bySymbol,
            ],
        ]);
    }

    public function performance(Request $request): JsonResponse
    {
        $reports = PerformanceReport::query()
            ->where('user_id', $request->user()->id)
            ->latest('period_end')
            ->paginate((int) $request->integer('per_page', 12));

        return PerformanceReportResource::collection($reports)->response();
    }
}
