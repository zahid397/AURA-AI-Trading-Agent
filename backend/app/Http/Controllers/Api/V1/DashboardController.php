<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentEvent;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use App\Models\RiskEvaluation;
use App\Models\RiskPolicy;
use App\Models\Strategy;
use App\Models\TradeProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Bucket thresholds for the risk_score (0-1, higher = more rules
     * passed = safer) of the user's most recent RiskEvaluation into the
     * four levels the frontend's RiskLevel type expects. No evaluation yet
     * -> LOW, matching the documented demo baseline.
     */
    private const RISK_LEVEL_THRESHOLDS = [
        ['min' => 0.90, 'level' => 'LOW'],
        ['min' => 0.70, 'level' => 'MODERATE'],
        ['min' => 0.50, 'level' => 'ELEVATED'],
    ];

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $latest = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->latest('snapshot_at')
            ->first();

        $earliest = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->oldest('snapshot_at')
            ->first();

        $openPositions = Position::query()
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->count();

        $pendingProposals = TradeProposal::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $activePolicy = RiskPolicy::query()->where('active', true)->first();

        $totalPl = $latest !== null
            ? (string) ((float) $latest->unrealized_pl + (float) $latest->realized_pl)
            : null;

        return response()->json([
            'data' => [
                'balance' => $latest !== null ? (string) $latest->equity : null,
                'total_pl' => $totalPl,
                'unrealized_pl' => $latest !== null ? (string) $latest->unrealized_pl : null,
                'realized_pl' => $latest !== null ? (string) $latest->realized_pl : null,
                'buying_power' => $latest !== null ? (string) $latest->buying_power : null,
                'risk_level' => $this->currentRiskLevel($user->id),
                'starting_equity' => $earliest !== null ? (string) $earliest->equity : null,
                'as_of' => $latest?->snapshot_at,
                'latest_decision' => $this->latestDecisionSummary($user->id),
                'open_positions_count' => $openPositions,
                'pending_proposals_count' => $pendingProposals,
                'active_risk_policy_version' => $activePolicy?->version,
            ],
        ]);
    }

    public function equityCurve(Request $request): JsonResponse
    {
        $user = $request->user();
        $since = match ($request->string('range', '1M')->toString()) {
            '1D' => now()->subDay(),
            '1W' => now()->subWeek(),
            '3M' => now()->subMonths(3),
            '1Y' => now()->subYear(),
            'ALL' => now()->subYears(20),
            default => now()->subMonth(),
        };

        $points = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->where('snapshot_at', '>=', $since)
            ->orderBy('snapshot_at')
            ->get(['snapshot_at', 'equity'])
            ->map(fn (PortfolioSnapshot $s) => [
                't' => $s->snapshot_at->toIso8601String(),
                'equity' => (string) $s->equity,
            ]);

        return response()->json(['data' => $points]);
    }

    private function currentRiskLevel(string $userId): string
    {
        $evaluation = RiskEvaluation::query()
            ->whereHas('tradeProposal', fn ($q) => $q->where('user_id', $userId))
            ->latest('evaluated_at')
            ->first();

        if ($evaluation === null) {
            return 'LOW';
        }

        $score = (float) $evaluation->risk_score;

        foreach (self::RISK_LEVEL_THRESHOLDS as $bucket) {
            if ($score >= $bucket['min']) {
                return $bucket['level'];
            }
        }

        return 'HIGH';
    }

    private function latestDecisionSummary(string $userId): ?array
    {
        $strategy = Strategy::query()
            ->whereHas('agentRun', fn ($q) => $q->where('user_id', $userId))
            ->latest('created_at')
            ->first();

        if ($strategy === null) {
            return null;
        }

        $proposal = TradeProposal::query()->where('strategy_id', $strategy->id)->first();

        return [
            'decision_id' => $strategy->id,
            'trade_proposal_id' => $proposal?->id,
            'symbol' => $strategy->symbol,
            'strategy_type' => $strategy->strategy_type->value,
            'regime' => $strategy->regime->value,
            'confidence' => (string) $strategy->confidence,
            'thesis' => $strategy->thesis,
            'status' => $proposal?->status?->value ?? 'analyzed',
            'created_at' => $strategy->created_at,
        ];
    }

    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();

        $events = AgentEvent::query()
            ->whereHas('agentRun', fn ($q) => $q->where('user_id', $user->id))
            ->latest('received_at')
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'data' => $events->map(fn (AgentEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'agent_run_id' => $e->agent_run_id,
                'received_at' => $e->received_at,
            ]),
            'meta' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ],
        ]);
    }
}
