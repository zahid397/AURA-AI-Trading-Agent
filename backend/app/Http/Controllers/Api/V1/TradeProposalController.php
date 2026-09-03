<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\SubmissionChannel;
use App\Enums\TradeProposalStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExecuteTradeProposalRequest;
use App\Http\Requests\Api\V1\RejectTradeProposalRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\TradeProposalResource;
use App\Models\Order;
use App\Models\OrderLeg;
use App\Models\TradeProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TradeProposalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', Rule::in(array_column(TradeProposalStatus::cases(), 'value'))],
        ]);

        $proposals = TradeProposal::query()
            ->where('user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with('strategy')
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return TradeProposalResource::collection($proposals)->response();
    }

    public function show(Request $request, TradeProposal $tradeProposal): JsonResponse
    {
        $this->authorizeOwnership($request, $tradeProposal);

        $tradeProposal->load(['strategy.legs', 'riskEvaluation.ruleResults', 'orders.legs']);

        return TradeProposalResource::make($tradeProposal)->response();
    }

    public function approve(Request $request, TradeProposal $tradeProposal): JsonResponse
    {
        $this->authorizeOwnership($request, $tradeProposal);
        $this->guardExpiry($tradeProposal);

        if ($tradeProposal->status !== TradeProposalStatus::Pending) {
            throw new ApiException('PROPOSAL_NOT_PENDING', "Proposal is not pending (status: {$tradeProposal->status->value}).", 409);
        }

        $tradeProposal->update([
            'status' => TradeProposalStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return TradeProposalResource::make($tradeProposal->fresh())->response();
    }

    public function reject(RejectTradeProposalRequest $request, TradeProposal $tradeProposal): JsonResponse
    {
        $this->authorizeOwnership($request, $tradeProposal);

        if ($tradeProposal->status !== TradeProposalStatus::Pending) {
            throw new ApiException('PROPOSAL_NOT_PENDING', "Proposal is not pending (status: {$tradeProposal->status->value}).", 409);
        }

        $tradeProposal->update([
            'status' => TradeProposalStatus::Rejected,
            'rejected_reason' => $request->validated('reason') ?? 'Rejected by user.',
        ]);

        return TradeProposalResource::make($tradeProposal->fresh())->response();
    }

    /**
     * Execution is idempotent on the client-supplied Idempotency-Key
     * header: replaying the same key for the same proposal always returns
     * the original order, never creates a duplicate. A second *distinct*
     * execution attempt against an already-executed or expired proposal
     * is rejected outright.
     */
    public function execute(ExecuteTradeProposalRequest $request, TradeProposal $tradeProposal): JsonResponse
    {
        $this->authorizeOwnership($request, $tradeProposal);

        $idempotencyKey = $request->idempotencyKey();

        if (blank($idempotencyKey)) {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'The Idempotency-Key header is required to execute a trade proposal.', 400);
        }

        // Replaying a known key always short-circuits to the original
        // result, regardless of the proposal's current status — this is
        // what makes retries from a flaky client safe.
        $existingOrder = Order::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existingOrder) {
            if ($existingOrder->trade_proposal_id !== $tradeProposal->id) {
                throw new ApiException('IDEMPOTENCY_KEY_REUSED', 'This idempotency key was already used for a different trade proposal.', 409);
            }

            return OrderResource::make($existingOrder->load('legs'))->response();
        }

        $this->guardExpiry($tradeProposal);

        if ($tradeProposal->status === TradeProposalStatus::Executed) {
            throw new ApiException('PROPOSAL_ALREADY_EXECUTED', 'This proposal has already been executed.', 409);
        }

        if ($tradeProposal->status === TradeProposalStatus::Expired) {
            throw new ApiException('PROPOSAL_EXPIRED', 'This proposal has expired and can no longer be executed.', 409);
        }

        if ($tradeProposal->status !== TradeProposalStatus::Approved) {
            throw new ApiException('PROPOSAL_NOT_APPROVED', "Proposal must be approved before execution (status: {$tradeProposal->status->value}).", 409);
        }

        $strategy = $tradeProposal->strategy()->with('legs')->firstOrFail();
        $qty = (int) ($request->validated('qty') ?? 1);

        $order = DB::transaction(function () use ($tradeProposal, $strategy, $idempotencyKey, $qty) {
            $order = Order::query()->create([
                'trade_proposal_id' => $tradeProposal->id,
                'broker_order_id' => null,
                'client_order_id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'status' => OrderStatus::New,
                'order_class' => $strategy->legs->count() > 1 ? 'mleg' : 'simple',
                'symbol' => $strategy->symbol,
                'qty' => $qty,
                // No live Alpaca connectivity in this environment: the
                // execution path documented in docs/architecture.md routes
                // through the ai-engine's AlpacaGateway, which is out of
                // process here. Recorded as a demo submission so the audit
                // trail never implies a real broker channel handled it.
                'submitted_via' => SubmissionChannel::Demo,
                'raw_request' => ['qty' => $qty, 'strategy_id' => $strategy->id],
                'raw_response' => null,
                'submitted_at' => now(),
            ]);

            foreach ($strategy->legs as $leg) {
                OrderLeg::query()->create([
                    'order_id' => $order->id,
                    'occ_symbol' => $leg->occ_symbol,
                    'side' => $leg->action,
                    'ratio_qty' => $leg->ratio_qty,
                    'position_intent' => $leg->position_intent,
                    'filled_qty' => 0,
                    'filled_avg_price' => null,
                ]);
            }

            $tradeProposal->update(['status' => TradeProposalStatus::Executed]);

            return $order;
        });

        return OrderResource::make($order->load('legs'))->response()->setStatusCode(201);
    }

    private function guardExpiry(TradeProposal $tradeProposal): void
    {
        // Both pending (not yet approved) and approved-but-not-yet-executed
        // proposals can go stale — either one must be caught before the
        // proposal is acted on further.
        $expirable = [TradeProposalStatus::Pending, TradeProposalStatus::Approved];

        if (in_array($tradeProposal->status, $expirable, true) && $tradeProposal->expires_at->isPast()) {
            $tradeProposal->update(['status' => TradeProposalStatus::Expired]);
            throw new ApiException('PROPOSAL_EXPIRED', 'This proposal has expired.', 409);
        }
    }

    private function authorizeOwnership(Request $request, TradeProposal $tradeProposal): void
    {
        if ($tradeProposal->user_id !== $request->user()->id) {
            throw new ApiException('FORBIDDEN', 'You do not have access to this trade proposal.', 403);
        }
    }
}
