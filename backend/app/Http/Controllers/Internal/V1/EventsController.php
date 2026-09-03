<?php

namespace App\Http\Controllers\Internal\V1;

use App\Enums\AgentType;
use App\Enums\LogLevel;
use App\Enums\OrderStatus;
use App\Enums\RiskResult;
use App\Enums\RunStatus;
use App\Enums\TradeProposalStatus;
use App\Events\AgentEventReceived;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentEvent;
use App\Models\AgentLog;
use App\Models\AgentRun;
use App\Models\AiDecision;
use App\Models\Order;
use App\Models\OrderLeg;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use App\Models\RiskEvaluation;
use App\Models\RiskPolicy;
use App\Models\RiskRuleResult;
use App\Models\Strategy;
use App\Models\StrategyLeg;
use App\Models\TradeProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Receives signed idempotent event callbacks from the FastAPI ai-engine
 * (docs/architecture.md "Internal API contract"). Authentication for this
 * route is handled entirely by the `internal.signature` middleware
 * (App\Http\Middleware\VerifyInternalSignature) — signature verification
 * and timestamp-skew rejection both happen before this controller runs.
 *
 * This controller is responsible for the rest of the contract:
 * event_id uniqueness (idempotent no-op on replay) -> payload shape
 * validation per `type` -> persistence inside a DB transaction. Any
 * failure returns 4xx and does not partially apply the event.
 */
class EventsController extends Controller
{
    private const TYPES = [
        'run.started',
        'thesis.generated',
        'strategy.proposed',
        'risk.evaluated',
        'approval.requested',
        'order.submitted',
        'order.updated',
        'position.updated',
        'portfolio.updated',
        'run.completed',
        'run.failed',
        'run.rejected',
        'learning.recommendation',
    ];

    public function store(Request $request): JsonResponse
    {
        $envelope = $this->validateEnvelope($request);

        // Idempotency: a replayed event_id is a no-op that still returns
        // 200, without reprocessing. Checked before touching domain
        // tables so retries from the ai-engine are always safe.
        $existing = AgentEvent::query()->where('event_id', $envelope['event_id'])->first();

        if ($existing) {
            return response()->json([
                'data' => [
                    'event_id' => $existing->event_id,
                    'status' => 'duplicate_ignored',
                ],
            ]);
        }

        $agentRun = AgentRun::query()->find($envelope['run_id']);

        if (! $agentRun) {
            throw new ApiException('UNKNOWN_RUN', "No agent_runs row found for run_id '{$envelope['run_id']}'.", 422);
        }

        $payload = $this->validatePayload($envelope['type'], $envelope['payload'] ?? []);

        $createdId = null;

        $agentEvent = DB::transaction(function () use ($envelope, $payload, $agentRun, &$createdId) {
            $createdId = $this->applyEvent($envelope['type'], $payload, $agentRun, $envelope);

            return AgentEvent::query()->create([
                'event_id' => $envelope['event_id'],
                'agent_run_id' => $agentRun->id,
                'type' => $envelope['type'],
                'payload' => $envelope['payload'] ?? [],
                'signature_valid' => true,
                'received_at' => now(),
            ]);
        });

        // Best-effort: broadcasting isn't wired to Reverb yet, so a
        // failure here must never affect the 200 already earned by a
        // successfully persisted event. The frontend polls as a fallback
        // until real-time broadcasting lands.
        try {
            AgentEventReceived::dispatch($agentEvent);
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch AgentEventReceived', ['exception' => $e]);
        }

        return response()->json([
            'data' => array_filter([
                'event_id' => $agentEvent->event_id,
                'status' => 'processed',
                'created_id' => $createdId,
            ], fn ($v) => $v !== null),
        ], 200);
    }

    /**
     * @return array{event_id: string, run_id: string, trace_id: string, type: string, payload: array<string, mixed>, occurred_at: string}
     */
    private function validateEnvelope(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'event_id' => ['required', 'uuid'],
            'run_id' => ['required', 'uuid'],
            'trace_id' => ['required', 'uuid'],
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'payload' => ['present', 'array'],
            'occurred_at' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Per-type payload validation. Schemas here are the Laravel-side half
     * of the "validated against a Pydantic/Resource schema on both ends"
     * contract in docs/architecture.md; the ai-engine's Pydantic models
     * are the source of truth and are being finalized in parallel, so
     * these intentionally validate only the fields this controller
     * persists rather than the full event shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(string $type, array $payload): array
    {
        $rules = match ($type) {
            'run.started' => [],
            'thesis.generated' => [
                'thesis' => ['required', 'string'],
                'evidence' => ['present', 'array'],
                'confidence' => ['required', 'numeric', 'between:0,1'],
                'model_provider' => ['required', 'string'],
                'model_name' => ['required', 'string'],
                'prompt_version' => ['required', 'string'],
                'feature_snapshot' => ['present', 'array'],
            ],
            'strategy.proposed' => [
                'strategy_id' => ['required', 'uuid'],
                'symbol' => ['required', 'string'],
                'strategy_type' => ['required', 'string'],
                'regime' => ['required', 'string'],
                'thesis' => ['required', 'string'],
                'confidence' => ['required', 'numeric', 'between:0,1'],
                'net_debit' => ['nullable', 'numeric'],
                'net_credit' => ['nullable', 'numeric'],
                'max_profit' => ['required', 'numeric'],
                'max_loss' => ['required', 'numeric'],
                'breakevens' => ['present', 'array'],
                'probability_of_profit' => ['required', 'numeric', 'between:0,1'],
                'liquidity_score' => ['required', 'numeric', 'between:0,1'],
                'score' => ['required', 'numeric', 'between:0,1'],
                'score_breakdown' => ['present', 'array'],
                'quote_timestamp' => ['required', 'date'],
                'dte' => ['required', 'integer', 'min:0'],
                'rank' => ['nullable', 'integer'],
                'selected' => ['boolean'],
                'legs' => ['required', 'array', 'min:1'],
                'legs.*.action' => ['required', 'string'],
                'legs.*.occ_symbol' => ['required', 'string'],
                'legs.*.underlying' => ['required', 'string'],
                'legs.*.option_type' => ['required', 'string'],
                'legs.*.strike' => ['required', 'numeric'],
                'legs.*.expiration' => ['required', 'date'],
                'legs.*.position_intent' => ['required', 'string'],
                'legs.*.ratio_qty' => ['required', 'integer', 'min:1'],
                'legs.*.quote_bid' => ['required', 'numeric'],
                'legs.*.quote_ask' => ['required', 'numeric'],
                'legs.*.quote_time' => ['required', 'date'],
            ],
            'risk.evaluated' => [
                'strategy_id' => ['required', 'uuid'],
                'trade_proposal_id' => ['required', 'uuid'],
                'risk_policy_version' => ['required', 'integer'],
                'result' => ['required', 'string', Rule::in(array_column(RiskResult::cases(), 'value'))],
                'risk_score' => ['required', 'numeric', 'between:0,1'],
                'rule_results' => ['present', 'array'],
                'rule_results.*.rule_key' => ['required', 'string'],
                'rule_results.*.description' => ['required', 'string'],
                'rule_results.*.observed_value' => ['required'],
                'rule_results.*.limit_value' => ['required'],
                'rule_results.*.passed' => ['required', 'boolean'],
                'rule_results.*.severity' => ['required', 'string'],
                'rule_results.*.explanation' => ['required', 'string'],
                'expires_at' => ['nullable', 'date'],
            ],
            'approval.requested' => [
                'trade_proposal_id' => ['required', 'uuid'],
            ],
            'order.submitted' => [
                'trade_proposal_id' => ['required', 'uuid'],
                'broker_order_id' => ['nullable', 'string'],
                'client_order_id' => ['required', 'string'],
                'idempotency_key' => ['required', 'string'],
                'order_class' => ['required', 'string'],
                'symbol' => ['required', 'string'],
                'qty' => ['required', 'integer', 'min:1'],
                'submitted_via' => ['required', 'string'],
                'raw_request' => ['nullable', 'array'],
                'raw_response' => ['nullable', 'array'],
                'legs' => ['present', 'array'],
            ],
            'order.updated' => [
                'order_id' => ['required', 'uuid'],
                'status' => ['required', 'string'],
                'raw_response' => ['nullable', 'array'],
                'legs' => ['nullable', 'array'],
            ],
            'position.updated' => [
                'user_id' => ['required', 'uuid'],
                'strategy_id' => ['nullable', 'uuid'],
                'symbol' => ['required', 'string'],
                'legs' => ['present', 'array'],
                'quantity' => ['required', 'integer'],
                'avg_entry_price' => ['required', 'numeric'],
                'current_value' => ['required', 'numeric'],
                'unrealized_pl' => ['required', 'numeric'],
                'status' => ['required', 'string'],
                'opened_at' => ['required', 'date'],
                'closed_at' => ['nullable', 'date'],
            ],
            'portfolio.updated' => [
                'user_id' => ['required', 'uuid'],
                'equity' => ['required', 'numeric'],
                'cash' => ['required', 'numeric'],
                'buying_power' => ['required', 'numeric'],
                'unrealized_pl' => ['required', 'numeric'],
                'realized_pl' => ['required', 'numeric'],
                'options_exposure' => ['required', 'numeric'],
                'snapshot_at' => ['nullable', 'date'],
            ],
            'run.completed' => [
                'summary' => ['nullable', 'string'],
            ],
            'run.failed' => [
                'summary' => ['nullable', 'string'],
            ],
            'run.rejected' => [
                'summary' => ['nullable', 'string'],
            ],
            'learning.recommendation' => [
                'message' => ['required', 'string'],
                'context' => ['nullable', 'array'],
            ],
            default => throw new ApiException('UNKNOWN_EVENT_TYPE', "Unhandled event type '{$type}'.", 422),
        };

        if ($rules === []) {
            return $payload;
        }

        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $envelope
     * @return string|null The id of the row this event created, when the caller
     *                     (the ai-engine) needs it to correlate a later event
     *                     (e.g. `strategy.proposed` -> `risk.evaluated` ->
     *                     `order.submitted` all referencing the same strategy/proposal).
     */
    private function applyEvent(string $type, array $payload, AgentRun $agentRun, array $envelope): ?string
    {
        $occurredAt = $envelope['occurred_at'];

        return match ($type) {
            'run.started' => tap(null, fn () => $agentRun->update([
                'status' => RunStatus::Running,
                'started_at' => $agentRun->started_at ?? $occurredAt,
            ])),
            'thesis.generated' => tap(null, fn () => AiDecision::query()->create([
                'agent_run_id' => $agentRun->id,
                'thesis' => $payload['thesis'],
                'evidence' => $payload['evidence'],
                'confidence' => $payload['confidence'],
                'model_provider' => $payload['model_provider'],
                'model_name' => $payload['model_name'],
                'prompt_version' => $payload['prompt_version'],
                'feature_snapshot' => $payload['feature_snapshot'],
            ])),
            'strategy.proposed' => $this->applyStrategyProposed($payload, $agentRun),
            'risk.evaluated' => $this->applyRiskEvaluated($payload, $agentRun),
            'approval.requested' => tap(null, fn () => $this->applyApprovalRequested($payload, $agentRun)),
            'order.submitted' => tap(null, fn () => $this->applyOrderSubmitted($payload)),
            'order.updated' => tap(null, fn () => $this->applyOrderUpdated($payload)),
            'position.updated' => tap(null, fn () => $this->applyPositionUpdated($payload)),
            'portfolio.updated' => tap(null, fn () => $this->applyPortfolioUpdated($payload)),
            'run.completed' => tap(null, fn () => $agentRun->update([
                'status' => RunStatus::Completed,
                'completed_at' => $occurredAt,
                'summary' => $payload['summary'] ?? $agentRun->summary,
            ])),
            'run.failed' => tap(null, fn () => $agentRun->update([
                'status' => RunStatus::Failed,
                'completed_at' => $occurredAt,
                'summary' => $payload['summary'] ?? $agentRun->summary,
            ])),
            'run.rejected' => tap(null, fn () => $agentRun->update([
                'status' => RunStatus::Rejected,
                'completed_at' => $occurredAt,
                'summary' => $payload['summary'] ?? $agentRun->summary,
            ])),
            'learning.recommendation' => tap(null, fn () => AgentLog::query()->create([
                'agent_run_id' => $agentRun->id,
                'agent_type' => AgentType::Learning,
                'level' => LogLevel::Info,
                'message' => $payload['message'],
                'context' => $payload['context'] ?? [],
            ])),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyStrategyProposed(array $payload, AgentRun $agentRun): string
    {
        $strategy = Strategy::query()->create([
            'id' => $payload['strategy_id'],
            'agent_run_id' => $agentRun->id,
            'symbol' => $payload['symbol'],
            'strategy_type' => $payload['strategy_type'],
            'regime' => $payload['regime'],
            'thesis' => $payload['thesis'],
            'confidence' => $payload['confidence'],
            'net_debit' => $payload['net_debit'] ?? null,
            'net_credit' => $payload['net_credit'] ?? null,
            'max_profit' => $payload['max_profit'],
            'max_loss' => $payload['max_loss'],
            'breakevens' => $payload['breakevens'],
            'probability_of_profit' => $payload['probability_of_profit'],
            'liquidity_score' => $payload['liquidity_score'],
            'score' => $payload['score'],
            'score_breakdown' => $payload['score_breakdown'],
            'quote_timestamp' => $payload['quote_timestamp'],
            'dte' => $payload['dte'],
            'rank' => $payload['rank'] ?? null,
            'selected' => $payload['selected'] ?? false,
        ]);

        foreach ($payload['legs'] as $leg) {
            StrategyLeg::query()->create([
                'strategy_id' => $strategy->id,
                'action' => $leg['action'],
                'occ_symbol' => $leg['occ_symbol'],
                'underlying' => $leg['underlying'],
                'option_type' => $leg['option_type'],
                'strike' => $leg['strike'],
                'expiration' => $leg['expiration'],
                'position_intent' => $leg['position_intent'],
                'ratio_qty' => $leg['ratio_qty'],
                'quote_bid' => $leg['quote_bid'],
                'quote_ask' => $leg['quote_ask'],
                'delta' => $leg['delta'] ?? null,
                'gamma' => $leg['gamma'] ?? null,
                'theta' => $leg['theta'] ?? null,
                'vega' => $leg['vega'] ?? null,
                'open_interest' => $leg['open_interest'] ?? null,
                'volume' => $leg['volume'] ?? null,
                'quote_time' => $leg['quote_time'],
            ]);
        }

        return $strategy->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyRiskEvaluated(array $payload, AgentRun $agentRun): string
    {
        $riskPolicy = RiskPolicy::query()->where('version', $payload['risk_policy_version'])->firstOrFail();

        // Keyed on the ai-engine-supplied `trade_proposal_id` (not the
        // agent_run/strategy pair) so this id is known to the caller
        // *before* this event is sent -- `order.submitted` references the
        // same id later without needing a round trip to discover it.
        $proposal = TradeProposal::query()->firstOrCreate(
            ['id' => $payload['trade_proposal_id']],
            [
                'agent_run_id' => $agentRun->id,
                'strategy_id' => $payload['strategy_id'],
                'user_id' => $agentRun->user_id,
                'status' => TradeProposalStatus::Pending,
                'expires_at' => $payload['expires_at'] ?? now()->addHours(4),
            ]
        );

        $evaluation = RiskEvaluation::query()->create([
            'trade_proposal_id' => $proposal->id,
            'risk_policy_id' => $riskPolicy->id,
            'risk_policy_version' => $riskPolicy->version,
            'result' => $payload['result'],
            'risk_score' => $payload['risk_score'],
            'evaluated_at' => now(),
        ]);

        foreach ($payload['rule_results'] as $rule) {
            RiskRuleResult::query()->create([
                'risk_evaluation_id' => $evaluation->id,
                'rule_key' => $rule['rule_key'],
                'description' => $rule['description'],
                'observed_value' => (string) $rule['observed_value'],
                'limit_value' => (string) $rule['limit_value'],
                'passed' => $rule['passed'],
                'severity' => $rule['severity'],
                'explanation' => $rule['explanation'],
            ]);
        }

        $proposal->risk_evaluation_id = $evaluation->id;

        if ($payload['result'] === RiskResult::Rejected->value) {
            $proposal->status = TradeProposalStatus::Rejected;
            $proposal->rejected_reason = 'Rejected by automated risk evaluation.';
        }

        $proposal->save();

        return $proposal->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyApprovalRequested(array $payload, AgentRun $agentRun): void
    {
        $agentRun->update(['status' => RunStatus::AwaitingApproval]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyOrderSubmitted(array $payload): void
    {
        $proposal = TradeProposal::query()->findOrFail($payload['trade_proposal_id']);

        $order = Order::query()->firstOrCreate(
            ['idempotency_key' => $payload['idempotency_key']],
            [
                'trade_proposal_id' => $proposal->id,
                'broker_order_id' => $payload['broker_order_id'] ?? null,
                'client_order_id' => $payload['client_order_id'],
                'status' => OrderStatus::New,
                'order_class' => $payload['order_class'],
                'symbol' => $payload['symbol'],
                'qty' => $payload['qty'],
                'submitted_via' => $payload['submitted_via'],
                'raw_request' => $payload['raw_request'] ?? null,
                'raw_response' => $payload['raw_response'] ?? null,
                'submitted_at' => now(),
            ]
        );

        foreach ($payload['legs'] as $leg) {
            OrderLeg::query()->create([
                'order_id' => $order->id,
                'occ_symbol' => $leg['occ_symbol'],
                'side' => $leg['side'],
                'ratio_qty' => $leg['ratio_qty'],
                'position_intent' => $leg['position_intent'],
                'filled_qty' => $leg['filled_qty'] ?? 0,
                'filled_avg_price' => $leg['filled_avg_price'] ?? null,
            ]);
        }

        $proposal->update(['status' => TradeProposalStatus::Executed]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyOrderUpdated(array $payload): void
    {
        $order = Order::query()->findOrFail($payload['order_id']);

        $order->update([
            'status' => $payload['status'],
            'raw_response' => $payload['raw_response'] ?? $order->raw_response,
        ]);

        foreach ($payload['legs'] ?? [] as $legUpdate) {
            if (! isset($legUpdate['occ_symbol'])) {
                continue;
            }

            OrderLeg::query()
                ->where('order_id', $order->id)
                ->where('occ_symbol', $legUpdate['occ_symbol'])
                ->update(array_filter([
                    'filled_qty' => $legUpdate['filled_qty'] ?? null,
                    'filled_avg_price' => $legUpdate['filled_avg_price'] ?? null,
                ], fn ($v) => $v !== null));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyPositionUpdated(array $payload): void
    {
        Position::query()->updateOrCreate(
            [
                'user_id' => $payload['user_id'],
                'symbol' => $payload['symbol'],
                'status' => 'open',
            ],
            [
                'strategy_id' => $payload['strategy_id'] ?? null,
                'legs' => $payload['legs'],
                'quantity' => $payload['quantity'],
                'avg_entry_price' => $payload['avg_entry_price'],
                'current_value' => $payload['current_value'],
                'unrealized_pl' => $payload['unrealized_pl'],
                'status' => $payload['status'],
                'opened_at' => $payload['opened_at'],
                'closed_at' => $payload['closed_at'] ?? null,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyPortfolioUpdated(array $payload): void
    {
        PortfolioSnapshot::query()->create([
            'user_id' => $payload['user_id'],
            'snapshot_at' => $payload['snapshot_at'] ?? now(),
            'equity' => $payload['equity'],
            'cash' => $payload['cash'],
            'buying_power' => $payload['buying_power'],
            'unrealized_pl' => $payload['unrealized_pl'],
            'realized_pl' => $payload['realized_pl'],
            'options_exposure' => $payload['options_exposure'],
        ]);
    }
}
