<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentStatus;
use App\Enums\RunStatus;
use App\Enums\TradeProposalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateRiskPolicyRequest;
use App\Http\Resources\Api\V1\RiskPolicyResource;
use App\Http\Resources\Api\V1\RiskRuleResultResource;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use App\Models\RiskPolicy;
use App\Models\RiskRuleResult;
use App\Models\TradeProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiskController extends Controller
{
    /**
     * Risk-score buckets for the Risk Management gauge. Unlike
     * RiskEvaluation::risk_score (0-1, higher = safer, scores a single
     * trade proposal against a policy at evaluation time -- see
     * DashboardController::RISK_LEVEL_THRESHOLDS), this scores the whole
     * account's *current* standing (0-100, higher = riskier) so it lines
     * up with GaugeChart's green/yellow/red bands at 40/70.
     */
    private const RISK_SCORE_LEVELS = [
        ['max' => 40.0, 'level' => 'LOW'],
        ['max' => 70.0, 'level' => 'MODERATE'],
        ['max' => 90.0, 'level' => 'ELEVATED'],
    ];

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        $policy = RiskPolicy::query()->where('active', true)->first();

        $resultCounts = DB::table('risk_evaluations')->select('result', DB::raw('count(*) as total'))
            ->groupBy('result')
            ->pluck('total', 'result');

        $criticalAlertCount = RiskRuleResult::query()
            ->where('severity', 'critical')
            ->where('passed', false)
            ->count();

        // limits.max_daily_loss_pct / max_drawdown_pct / max_total_exposure_pct /
        // max_position_risk_pct are already-a-percentage numbers (e.g. 3.0 = 3%),
        // per RiskPolicy's seeded shape -- no /100 needed to read them.
        $limits = $policy?->limits ?? [];

        $snapshots = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->oldest('snapshot_at')
            ->get();
        $latest = $snapshots->last();
        $previous = $snapshots->count() > 1 ? $snapshots->slice(-2, 1)->first() : null;

        // decimal-cast columns (equity, options_exposure, ...) come back as
        // strings from Eloquent -- must go through (float)/bcmath before any
        // arithmetic, the same trap documented elsewhere in this codebase.
        $equity = $latest !== null ? (float) $latest->equity : null;

        $dailyPl = ($latest !== null && $previous !== null)
            ? bcsub((string) $latest->equity, (string) $previous->equity, 4)
            : null;
        $dailyLossUsed = $dailyPl !== null ? max(0.0, -1 * (float) $dailyPl) : 0.0;

        $maxDailyLossPct = (float) ($limits['max_daily_loss_pct'] ?? 0);
        $dailyLossLimit = $equity !== null ? round($equity * $maxDailyLossPct / 100, 4) : null;
        $remainingCapacity = $dailyLossLimit !== null ? max(0.0, round($dailyLossLimit - $dailyLossUsed, 4)) : null;

        $exposurePct = ($latest !== null && $equity > 0)
            ? round(((float) $latest->options_exposure / $equity) * 100, 4)
            : 0.0;
        $maxExposurePct = (float) ($limits['max_total_exposure_pct'] ?? 0);

        $maxPositionRiskPct = (float) ($limits['max_position_risk_pct'] ?? 0);
        $positionSizingBudget = $equity !== null ? round($equity * $maxPositionRiskPct / 100, 4) : null;

        // Running peak-to-date drawdown across every snapshot on file, so the
        // series and the "current" figure (its last point) are always consistent.
        $peak = null;
        $drawdownSeries = [];
        foreach ($snapshots as $snapshot) {
            $snapshotEquity = (float) $snapshot->equity;
            $peak = $peak === null ? $snapshotEquity : max($peak, $snapshotEquity);
            $drawdownPctAtPoint = $peak > 0 ? max(0.0, ($peak - $snapshotEquity) / $peak * 100) : 0.0;
            $drawdownSeries[] = [
                't' => $snapshot->snapshot_at->toIso8601String(),
                'drawdown_pct' => round($drawdownPctAtPoint, 4),
            ];
        }
        $drawdownPct = $drawdownSeries === [] ? 0.0 : end($drawdownSeries)['drawdown_pct'];
        $maxDrawdownPct = (float) ($limits['max_drawdown_pct'] ?? 0);

        $riskScore = $this->computeRiskScore($exposurePct, $maxExposurePct, $drawdownPct, $maxDrawdownPct, $dailyLossUsed, $dailyLossLimit ?? 0.0);

        $openPositions = Position::query()
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->with('strategy')
            ->get();

        $exposureByUnderlying = $openPositions->groupBy('symbol')
            ->map(function ($group, string $symbol) use ($equity) {
                $value = $group->reduce(fn (?string $carry, Position $p) => bcadd($carry ?? '0', (string) $p->current_value, 4), '0');

                return [
                    'symbol' => $symbol,
                    'exposure' => $value,
                    'pct' => ($equity !== null && $equity > 0) ? round(((float) $value / $equity) * 100, 4) : 0.0,
                ];
            })->values();

        $exposureByStrategy = $openPositions->filter(fn (Position $p) => $p->strategy !== null)
            ->groupBy(fn (Position $p) => $p->strategy->strategy_type->value)
            ->map(function ($group, string $strategyType) use ($equity) {
                $value = $group->reduce(fn (?string $carry, Position $p) => bcadd($carry ?? '0', (string) $p->current_value, 4), '0');

                return [
                    'strategy_type' => $strategyType,
                    'exposure' => $value,
                    'pct' => ($equity !== null && $equity > 0) ? round(((float) $value / $equity) * 100, 4) : 0.0,
                ];
            })->values();

        return response()->json([
            'data' => [
                // Same null-guard as PortfolioController::summary() -- risk_policies
                // is global, not user-scoped, so this is always populated once
                // seeded, but nothing prevents an unseeded/freshly-deactivated
                // state from reaching here and crashing instead of degrading.
                'active_policy' => $policy ? RiskPolicyResource::make($policy) : null,
                'evaluations_by_result' => $resultCounts,
                'open_critical_alerts' => $criticalAlertCount,
                'risk_score' => $riskScore,
                'risk_level' => $this->riskLevelForScore($riskScore),
                'daily_pl' => $dailyPl,
                'daily_loss_limit' => $dailyLossLimit !== null ? number_format($dailyLossLimit, 4, '.', '') : null,
                'exposure_pct' => $exposurePct,
                'max_exposure_pct' => $maxExposurePct,
                'position_sizing_budget' => $positionSizingBudget !== null ? number_format($positionSizingBudget, 4, '.', '') : null,
                'drawdown_pct' => $drawdownPct,
                'max_drawdown_pct' => $maxDrawdownPct,
                'remaining_capacity' => $remainingCapacity !== null ? number_format($remainingCapacity, 4, '.', '') : null,
                'kill_switch_engaged' => Agent::query()->where('enabled', true)->doesntExist(),
                'exposure_by_underlying' => $exposureByUnderlying,
                'exposure_by_strategy' => $exposureByStrategy,
                'drawdown_series' => $drawdownSeries,
            ],
        ]);
    }

    /**
     * Highest utilization ratio (0-100) across the three guardrails the
     * active policy enforces on the account as a whole. 0 = no utilization
     * of any limit, 100 = at or past one. Division-by-zero-safe: an unset
     * or zero limit contributes 0 rather than being divided by.
     */
    private function computeRiskScore(float $exposurePct, float $maxExposurePct, float $drawdownPct, float $maxDrawdownPct, float $dailyLossUsed, float $dailyLossLimit): float
    {
        $ratios = [
            $maxExposurePct > 0 ? $exposurePct / $maxExposurePct : 0.0,
            $maxDrawdownPct > 0 ? $drawdownPct / $maxDrawdownPct : 0.0,
            $dailyLossLimit > 0 ? $dailyLossUsed / $dailyLossLimit : 0.0,
        ];

        return round(min(1.0, max(...$ratios)) * 100, 2);
    }

    private function riskLevelForScore(float $score): string
    {
        foreach (self::RISK_SCORE_LEVELS as $bucket) {
            if ($score < $bucket['max']) {
                return $bucket['level'];
            }
        }

        return 'HIGH';
    }

    public function showPolicy(Request $request): JsonResponse
    {
        if ($request->filled('version')) {
            $policy = RiskPolicy::query()->where('version', $request->integer('version'))->firstOrFail();

            return RiskPolicyResource::make($policy)->response();
        }

        $policy = RiskPolicy::query()->where('active', true)->firstOrFail();

        return RiskPolicyResource::make($policy)->response();
    }

    /**
     * Versioning contract: PATCH never mutates the active row in place.
     * It always inserts a brand-new row with version = max(version) + 1,
     * flips `active` from the old row to the new one inside a single
     * transaction, and leaves every prior version permanently readable via
     * GET /risk/policy?version=N.
     */
    public function updatePolicy(UpdateRiskPolicyRequest $request): JsonResponse
    {
        $newPolicy = DB::transaction(function () use ($request) {
            $current = RiskPolicy::query()->where('active', true)->lockForUpdate()->first();
            $nextVersion = (int) (RiskPolicy::query()->max('version') ?? 0) + 1;

            if ($current) {
                $current->update(['active' => false]);
            }

            return RiskPolicy::query()->create([
                'version' => $nextVersion,
                'name' => $request->validated('name') ?? $current?->name ?? 'Default risk policy',
                'limits' => $request->validated('limits'),
                'active' => true,
                'created_by' => $request->user()->id,
            ]);
        });

        return RiskPolicyResource::make($newPolicy)->response()->setStatusCode(201);
    }

    public function alerts(Request $request): JsonResponse
    {
        $alerts = RiskRuleResult::query()
            ->whereIn('severity', ['warning', 'critical'])
            ->where('passed', false)
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return RiskRuleResultResource::collection($alerts)->response();
    }

    /**
     * Emergency stop: disables every agent, cancels every non-terminal run
     * and pending proposal for the current user, and leaves an audit trail
     * entry. This is a hard local stop — it does not depend on the
     * ai-engine being reachable.
     */
    public function killSwitch(Request $request): JsonResponse
    {
        $user = $request->user();

        $summary = DB::transaction(function () use ($user, $request) {
            $agentsDisabled = Agent::query()->update(['enabled' => false, 'status' => AgentStatus::Paused]);

            $runsCancelled = AgentRun::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [RunStatus::Pending, RunStatus::Running, RunStatus::AwaitingApproval])
                ->update(['status' => RunStatus::Cancelled, 'completed_at' => now()]);

            $proposalsRejected = TradeProposal::query()
                ->where('user_id', $user->id)
                ->where('status', TradeProposalStatus::Pending)
                ->update(['status' => TradeProposalStatus::Rejected, 'rejected_reason' => 'Kill switch activated.']);

            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => 'kill_switch_activated',
                'entity_type' => 'system',
                'entity_id' => null,
                'before' => null,
                'after' => [
                    'agents_disabled' => $agentsDisabled,
                    'runs_cancelled' => $runsCancelled,
                    'proposals_rejected' => $proposalsRejected,
                ],
                'request_id' => $request->attributes->get('request_id'),
                'ip' => $request->ip(),
            ]);

            return [
                'agents_disabled' => $agentsDisabled,
                'runs_cancelled' => $runsCancelled,
                'proposals_rejected' => $proposalsRejected,
            ];
        });

        return response()->json(['data' => $summary]);
    }
}
