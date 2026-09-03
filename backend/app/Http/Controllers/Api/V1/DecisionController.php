<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DecisionResource;
use App\Http\Resources\Api\V1\StrategyResource;
use App\Http\Resources\Api\V1\TradeProposalResource;
use App\Models\AiDecision;
use App\Models\TradeProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DecisionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $decisions = AiDecision::query()
            ->whereHas('agentRun', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('strategy')
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return DecisionResource::collection($decisions)->response();
    }

    public function show(Request $request, AiDecision $decision): JsonResponse
    {
        $this->authorizeOwnership($request, $decision);

        $decision->load('strategy.legs');

        return DecisionResource::make($decision)->response();
    }

    /**
     * Full explainability lineage for one decision: the thesis, the
     * strategy it produced, and (if the strategy was carried through risk
     * evaluation and execution) the resulting trade proposal, risk rule
     * results, and orders. Lets the UI answer "why did the agent do this,
     * and what happened next" from a single call.
     */
    public function trace(Request $request, AiDecision $decision): JsonResponse
    {
        $this->authorizeOwnership($request, $decision);

        $decision->load('strategy.legs');

        $tradeProposal = null;

        if ($decision->strategy_id) {
            $tradeProposal = TradeProposal::query()
                ->where('strategy_id', $decision->strategy_id)
                ->with(['riskEvaluation.ruleResults', 'orders.legs'])
                ->first();
        }

        return response()->json([
            'data' => [
                'decision' => DecisionResource::make($decision),
                'strategy' => StrategyResource::make($decision->strategy),
                'trade_proposal' => $tradeProposal ? TradeProposalResource::make($tradeProposal) : null,
            ],
        ]);
    }

    private function authorizeOwnership(Request $request, AiDecision $decision): void
    {
        if ($decision->agentRun->user_id !== $request->user()->id) {
            throw new ApiException('FORBIDDEN', 'You do not have access to this decision.', 403);
        }
    }
}
