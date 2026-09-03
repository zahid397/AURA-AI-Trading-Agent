<?php

namespace Database\Seeders;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Enums\AlpacaConnectionStatus;
use App\Enums\AlpacaEnvironment;
use App\Enums\BacktestStatus;
use App\Enums\Direction;
use App\Enums\OptionType;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\PositionIntent;
use App\Enums\PositionStatus;
use App\Enums\RiskResult;
use App\Enums\RiskSeverity;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\StrategyType;
use App\Enums\SubmissionChannel;
use App\Enums\TradeProposalStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiDecision;
use App\Models\AlpacaConnection;
use App\Models\BacktestResult;
use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use App\Models\MarketSnapshot;
use App\Models\Order;
use App\Models\OrderLeg;
use App\Models\PerformanceReport;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use App\Models\RiskEvaluation;
use App\Models\RiskPolicy;
use App\Models\RiskRuleResult;
use App\Models\Strategy;
use App\Models\StrategyLeg;
use App\Models\Trade;
use App\Models\TradeProposal;
use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One coherent hackathon-demo scenario for the AURA AI Trading Agent, built
 * around a single demo user (demo@aura.trading / password — see
 * backend/README.md). Intended to be run via `php artisan aura:demo-reset`
 * (migrate:fresh --seed) rather than repeatedly against an existing
 * database: the user/settings/connection/agents/risk-policy rows are
 * upsert-safe, but the run/strategy/order/position history below is
 * created fresh every time this seeder runs.
 *
 * All monetary figures are illustrative fixtures picked to match the
 * headline demo numbers (equity ~$100,245.69, total P&L +$245.69,
 * unrealized P&L +$182.34, buying power ~$78,120.45) — they are not the
 * output of double-entry bookkeeping across every table.
 */
class DemoSeeder extends Seeder
{
    private User $user;

    private RiskPolicy $riskPolicy;

    public function run(): void
    {
        DB::transaction(function () {
            $this->user = $this->createDemoUser();
            $this->createUserSettings();
            $this->createAlpacaConnection();
            $this->createAgents();
            $this->riskPolicy = $this->createRiskPolicy();

            $this->createSpyScenario();
            $this->createNvdaScenario();
            $this->createTslaScenario();
            $this->createOpenPositions();
            $this->createPortfolioSnapshots();
            $this->createBacktest();
            $this->createPerformanceReport();
        });
    }

    private function createDemoUser(): User
    {
        return User::query()->updateOrCreate(
            ['email' => 'demo@aura.trading'],
            [
                'name' => 'Demo Trader',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'timezone' => 'America/New_York',
                'currency_display' => 'USD',
                'default_page' => 'dashboard',
            ]
        );
    }

    private function createUserSettings(): void
    {
        // Never default a demo account straight into autonomous trading —
        // approval_required is the safe default even for the seeded demo.
        UserSettings::query()->updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'mode' => RunMode::ApprovalRequired,
                'confidence_threshold' => '0.6500',
                'notification_prefs' => ['email' => true, 'in_app' => true, 'risk_alerts' => true],
            ]
        );
    }

    private function createAlpacaConnection(): void
    {
        AlpacaConnection::query()->updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'environment' => AlpacaEnvironment::Paper,
                'account_id' => 'PA2QDEMO456',
                'api_key_last4' => 'K9F2',
                // Not a real secret — see backend/README.md. Still routed
                // through Crypt::encryptString so the seeder exercises the
                // exact same at-rest storage path production credentials use.
                'secret_ciphertext' => Crypt::encryptString('demo-fixture-not-a-real-secret'),
                'status' => AlpacaConnectionStatus::Connected,
                'capabilities' => [
                    'account_lookup' => true,
                    'option_chain' => true,
                    'place_option_order' => true,
                ],
                'last_synced_at' => now()->subMinutes(5),
            ]
        );
    }

    private function createAgents(): void
    {
        foreach (AgentType::cases() as $type) {
            Agent::query()->updateOrCreate(
                ['type' => $type],
                ['enabled' => true, 'status' => AgentStatus::Idle, 'config_version' => 1]
            );
        }
    }

    private function createRiskPolicy(): RiskPolicy
    {
        RiskPolicy::query()->where('active', true)->where('version', '!=', 1)->update(['active' => false]);

        return RiskPolicy::query()->updateOrCreate(
            ['version' => 1],
            [
                'name' => 'Default paper-trading risk policy',
                'limits' => [
                    'max_daily_loss_pct' => 3.0,
                    'max_drawdown_pct' => 10.0,
                    'max_position_risk_pct' => 2.0,
                    'max_total_exposure_pct' => 50.0,
                    'max_symbol_exposure_pct' => 15.0,
                    'min_confidence' => 0.65,
                    'max_quote_age_seconds' => 60,
                    'min_open_interest' => 100,
                    'max_spread_pct' => 10.0,
                    'max_open_positions' => 10,
                ],
                'active' => true,
                'created_by' => $this->user->id,
            ]
        );
    }

    /**
     * SPY bull call spread: the headline "pending approval" scenario — a
     * fresh run that stopped at awaiting_approval with one unactioned
     * trade proposal, ~87% decision confidence.
     */
    private function createSpyScenario(): void
    {
        $run = AgentRun::query()->create([
            'user_id' => $this->user->id,
            'trigger' => RunTrigger::Scheduled,
            'mode' => RunMode::ApprovalRequired,
            'status' => RunStatus::AwaitingApproval,
            'trace_id' => (string) Str::uuid(),
            'started_at' => now()->subMinutes(45),
            'completed_at' => null,
            'summary' => 'Scanning bullish continuation setups after SPY reclaimed the 20-day moving average on above-average volume.',
            'symbol_universe' => ['SPY', 'NVDA', 'TSLA'],
        ]);

        MarketSnapshot::query()->create([
            'agent_run_id' => $run->id,
            'symbol' => 'SPY',
            'snapshot_at' => now()->subMinutes(46),
            'price' => '582.4300',
            'volume' => 68123456,
            'indicators' => ['rsi_14' => 61.2, 'sma_20' => 574.10, 'sma_50' => 561.85, 'iv_rank' => 28.4],
        ]);

        $decision = AiDecision::query()->create([
            'agent_run_id' => $run->id,
            'thesis' => 'SPY reclaimed its 20-day moving average with rising volume and an RSI trending toward 65, indicating renewed bullish momentum without being overbought. A defined-risk bull call spread captures upside toward the next resistance zone near 595 while capping downside to the net debit paid.',
            'evidence' => [
                'technical' => ['rsi_14' => 61.2, 'trend' => 'above_20dma_and_50dma'],
                'volatility' => ['iv_rank' => 28.4, 'note' => 'Low IV rank favors a debit spread over a naked long call.'],
            ],
            'confidence' => '0.8700',
            'model_provider' => 'openai',
            'model_name' => 'gpt-4.1',
            'prompt_version' => 'strategy-thesis-v1',
            'feature_snapshot' => ['price' => 582.43, 'sma_20' => 574.10, 'sma_50' => 561.85, 'rsi_14' => 61.2, 'iv_rank' => 28.4],
        ]);

        $expiration = now()->addDays(30)->toDateString();

        $strategy = Strategy::query()->create([
            'agent_run_id' => $run->id,
            'symbol' => 'SPY',
            'strategy_type' => StrategyType::BullCallSpread,
            'regime' => Direction::Bullish,
            'thesis' => $decision->thesis,
            'confidence' => '0.8700',
            'net_debit' => '3.4500',
            'net_credit' => null,
            'max_profit' => '6.5500',
            'max_loss' => '3.4500',
            'breakevens' => ['583.45'],
            'probability_of_profit' => '0.5800',
            'liquidity_score' => '0.8900',
            'score' => '0.8400',
            'score_breakdown' => ['thesis_confidence' => 0.87, 'liquidity' => 0.89, 'risk_reward' => 0.76],
            'quote_timestamp' => now()->subMinutes(44),
            'dte' => 30,
            'rank' => 1,
            'selected' => true,
        ]);

        $decision->update(['strategy_id' => $strategy->id]);

        StrategyLeg::query()->create([
            'strategy_id' => $strategy->id,
            'action' => OrderSide::Buy,
            'occ_symbol' => $this->occSymbol('SPY', $expiration, 'C', 580),
            'underlying' => 'SPY',
            'option_type' => OptionType::Call,
            'strike' => '580.0000',
            'expiration' => $expiration,
            'position_intent' => PositionIntent::BuyToOpen,
            'ratio_qty' => 1,
            'quote_bid' => '9.80',
            'quote_ask' => '9.95',
            'delta' => '0.580000',
            'gamma' => '0.021000',
            'theta' => '-0.085000',
            'vega' => '0.412000',
            'open_interest' => 15234,
            'volume' => 4210,
            'quote_time' => now()->subMinutes(44),
        ]);

        StrategyLeg::query()->create([
            'strategy_id' => $strategy->id,
            'action' => OrderSide::Sell,
            'occ_symbol' => $this->occSymbol('SPY', $expiration, 'C', 595),
            'underlying' => 'SPY',
            'option_type' => OptionType::Call,
            'strike' => '595.0000',
            'expiration' => $expiration,
            'position_intent' => PositionIntent::SellToOpen,
            'ratio_qty' => 1,
            'quote_bid' => '6.45',
            'quote_ask' => '6.55',
            'delta' => '0.340000',
            'gamma' => '0.018000',
            'theta' => '-0.071000',
            'vega' => '0.358000',
            'open_interest' => 11897,
            'volume' => 3103,
            'quote_time' => now()->subMinutes(44),
        ]);

        $proposal = TradeProposal::query()->create([
            'agent_run_id' => $run->id,
            'strategy_id' => $strategy->id,
            'user_id' => $this->user->id,
            'status' => TradeProposalStatus::Pending,
            'expires_at' => now()->addHours(4),
        ]);

        $evaluation = RiskEvaluation::query()->create([
            'trade_proposal_id' => $proposal->id,
            'risk_policy_id' => $this->riskPolicy->id,
            'risk_policy_version' => $this->riskPolicy->version,
            'result' => RiskResult::ApprovalRequired,
            'risk_score' => '0.3800',
            'evaluated_at' => now()->subMinutes(40),
        ]);

        RiskRuleResult::query()->create([
            'risk_evaluation_id' => $evaluation->id,
            'rule_key' => 'min_confidence',
            'description' => 'Decision confidence must meet the policy minimum.',
            'observed_value' => '0.87',
            'limit_value' => '0.65',
            'passed' => true,
            'severity' => RiskSeverity::Info,
            'explanation' => 'Confidence 0.87 exceeds the 0.65 minimum required by policy v1.',
        ]);

        RiskRuleResult::query()->create([
            'risk_evaluation_id' => $evaluation->id,
            'rule_key' => 'max_position_risk_pct',
            'description' => 'Max loss as a percentage of equity must stay under the policy limit.',
            'observed_value' => '0.35',
            'limit_value' => '2.00',
            'passed' => true,
            'severity' => RiskSeverity::Info,
            'explanation' => 'Net debit risk is 0.35% of account equity, well under the 2% cap.',
        ]);

        RiskRuleResult::query()->create([
            'risk_evaluation_id' => $evaluation->id,
            'rule_key' => 'mode_requires_approval',
            'description' => 'Account mode requires a human decision before execution.',
            'observed_value' => 'approval_required',
            'limit_value' => 'approval_required',
            'passed' => true,
            'severity' => RiskSeverity::Warning,
            'explanation' => 'Account mode is approval_required; this proposal will not auto-execute.',
        ]);

        $proposal->update(['risk_evaluation_id' => $evaluation->id]);
    }

    /**
     * NVDA iron condor: a completed, already-executed run producing a
     * (now closed) four-leg position and one closed trade.
     */
    private function createNvdaScenario(): void
    {
        $run = AgentRun::query()->create([
            'user_id' => $this->user->id,
            'trigger' => RunTrigger::Scheduled,
            'mode' => RunMode::PaperAutonomous,
            'status' => RunStatus::Completed,
            'trace_id' => (string) Str::uuid(),
            'started_at' => now()->subDays(3)->subMinutes(20),
            'completed_at' => now()->subDays(3),
            'summary' => 'Sold an NVDA iron condor to harvest elevated post-earnings IV; the position was closed early for a partial profit.',
            'symbol_universe' => ['NVDA'],
        ]);

        $decision = AiDecision::query()->create([
            'agent_run_id' => $run->id,
            'thesis' => 'NVDA implied volatility remains elevated after earnings while realized volatility has compressed, favoring a premium-selling iron condor between the expected-move boundaries.',
            'evidence' => ['volatility' => ['iv_rank' => 71.0, 'iv_rv_spread' => 18.4]],
            'confidence' => '0.7900',
            'model_provider' => 'openai',
            'model_name' => 'gpt-4.1',
            'prompt_version' => 'strategy-thesis-v1',
            'feature_snapshot' => ['price' => 128.30, 'iv_rank' => 71.0],
        ]);

        $expiration = now()->addDays(21)->toDateString();

        $strategy = Strategy::query()->create([
            'agent_run_id' => $run->id,
            'symbol' => 'NVDA',
            'strategy_type' => StrategyType::IronCondor,
            'regime' => Direction::Neutral,
            'thesis' => $decision->thesis,
            'confidence' => '0.7900',
            'net_debit' => null,
            'net_credit' => '1.2500',
            'max_profit' => '1.2500',
            'max_loss' => '3.7500',
            'breakevens' => ['116.25', '133.75'],
            'probability_of_profit' => '0.6800',
            'liquidity_score' => '0.8200',
            'score' => '0.7600',
            'score_breakdown' => ['thesis_confidence' => 0.79, 'liquidity' => 0.82, 'risk_reward' => 0.62],
            'quote_timestamp' => now()->subDays(3)->subMinutes(19),
            'dte' => 21,
            'rank' => 1,
            'selected' => true,
        ]);

        $decision->update(['strategy_id' => $strategy->id]);

        $legs = [
            ['action' => OrderSide::Buy, 'occ_type' => 'P', 'option_type' => OptionType::Put, 'strike' => 110.0, 'intent' => PositionIntent::BuyToOpen, 'fill' => '0.55'],
            ['action' => OrderSide::Sell, 'occ_type' => 'P', 'option_type' => OptionType::Put, 'strike' => 115.0, 'intent' => PositionIntent::SellToOpen, 'fill' => '0.85'],
            ['action' => OrderSide::Sell, 'occ_type' => 'C', 'option_type' => OptionType::Call, 'strike' => 135.0, 'intent' => PositionIntent::SellToOpen, 'fill' => '0.90'],
            ['action' => OrderSide::Buy, 'occ_type' => 'C', 'option_type' => OptionType::Call, 'strike' => 140.0, 'intent' => PositionIntent::BuyToOpen, 'fill' => '0.60'],
        ];

        foreach ($legs as $leg) {
            StrategyLeg::query()->create([
                'strategy_id' => $strategy->id,
                'action' => $leg['action'],
                'occ_symbol' => $this->occSymbol('NVDA', $expiration, $leg['occ_type'], $leg['strike']),
                'underlying' => 'NVDA',
                'option_type' => $leg['option_type'],
                'strike' => number_format($leg['strike'], 4, '.', ''),
                'expiration' => $expiration,
                'position_intent' => $leg['intent'],
                'ratio_qty' => 1,
                'quote_bid' => '0.90',
                'quote_ask' => '1.05',
                'delta' => '0.180000',
                'gamma' => '0.015000',
                'theta' => '-0.045000',
                'vega' => '0.210000',
                'open_interest' => 5230,
                'volume' => 980,
                'quote_time' => now()->subDays(3)->subMinutes(19),
            ]);
        }

        $proposal = TradeProposal::query()->create([
            'agent_run_id' => $run->id,
            'strategy_id' => $strategy->id,
            'user_id' => $this->user->id,
            'status' => TradeProposalStatus::Executed,
            'expires_at' => now()->subDays(3)->addHours(4),
            'approved_at' => now()->subDays(3)->subMinutes(15),
            'approved_by' => $this->user->id,
        ]);

        $evaluation = RiskEvaluation::query()->create([
            'trade_proposal_id' => $proposal->id,
            'risk_policy_id' => $this->riskPolicy->id,
            'risk_policy_version' => $this->riskPolicy->version,
            'result' => RiskResult::Approved,
            'risk_score' => '0.2900',
            'evaluated_at' => now()->subDays(3)->subMinutes(18),
        ]);

        RiskRuleResult::query()->create([
            'risk_evaluation_id' => $evaluation->id,
            'rule_key' => 'max_position_risk_pct',
            'description' => 'Max loss as a percentage of equity must stay under the policy limit.',
            'observed_value' => '0.37',
            'limit_value' => '2.00',
            'passed' => true,
            'severity' => RiskSeverity::Info,
            'explanation' => 'Max loss of $375 on a $100k account is 0.37% of equity.',
        ]);

        $proposal->update(['risk_evaluation_id' => $evaluation->id]);

        $order = Order::query()->create([
            'trade_proposal_id' => $proposal->id,
            'broker_order_id' => 'demo-nvda-'.Str::random(8),
            'client_order_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => OrderStatus::Filled,
            'order_class' => 'mleg',
            'symbol' => 'NVDA',
            'qty' => 1,
            'submitted_via' => SubmissionChannel::Demo,
            'raw_request' => ['qty' => 1, 'strategy_id' => $strategy->id],
            'raw_response' => ['status' => 'filled'],
            'submitted_at' => now()->subDays(3)->subMinutes(14),
        ]);

        foreach ($legs as $leg) {
            OrderLeg::query()->create([
                'order_id' => $order->id,
                'occ_symbol' => $this->occSymbol('NVDA', $expiration, $leg['occ_type'], $leg['strike']),
                'side' => $leg['action'],
                'ratio_qty' => 1,
                'position_intent' => $leg['intent'],
                'filled_qty' => 1,
                'filled_avg_price' => $leg['fill'],
            ]);
        }

        Trade::query()->create([
            'order_id' => $order->id,
            'fill_id' => 'fill-nvda-'.Str::random(10),
            'qty' => 1,
            'price' => '1.2500',
            'side' => OrderSide::Sell,
            'filled_at' => now()->subDays(3)->subMinutes(14),
        ]);

        Position::query()->create([
            'user_id' => $this->user->id,
            'strategy_id' => $strategy->id,
            'symbol' => 'NVDA',
            'legs' => array_map(fn (array $leg) => [
                'occ_symbol' => $this->occSymbol('NVDA', $expiration, $leg['occ_type'], $leg['strike']),
                'side' => $leg['action']->value,
            ], $legs),
            'quantity' => 1,
            'avg_entry_price' => '1.2500',
            'current_value' => '0.0000',
            'unrealized_pl' => '0.0000',
            'status' => PositionStatus::Closed,
            'opened_at' => now()->subDays(3)->subMinutes(14),
            'closed_at' => now()->subDays(1),
        ]);
    }

    /**
     * TSLA bear put spread: a second completed + executed + closed
     * scenario, giving the demo a couple of distinct closed trades.
     */
    private function createTslaScenario(): void
    {
        $run = AgentRun::query()->create([
            'user_id' => $this->user->id,
            'trigger' => RunTrigger::Manual,
            'mode' => RunMode::ApprovalRequired,
            'status' => RunStatus::Completed,
            'trace_id' => (string) Str::uuid(),
            'started_at' => now()->subDays(5)->subMinutes(15),
            'completed_at' => now()->subDays(5),
            'summary' => 'Closed a TSLA bear put spread ahead of earnings to lock in gains and avoid gap risk.',
            'symbol_universe' => ['TSLA'],
        ]);

        $decision = AiDecision::query()->create([
            'agent_run_id' => $run->id,
            'thesis' => 'TSLA broke below its rising trendline on heavy volume with deteriorating delivery estimates; a bear put spread limits risk while targeting a retest of the prior consolidation zone.',
            'evidence' => ['technical' => ['trend' => 'broke_below_trendline', 'volume' => 'above_average']],
            'confidence' => '0.8100',
            'model_provider' => 'openai',
            'model_name' => 'gpt-4.1',
            'prompt_version' => 'strategy-thesis-v1',
            'feature_snapshot' => ['price' => 248.10, 'rsi_14' => 38.4],
        ]);

        $expiration = now()->addDays(45)->toDateString();

        $strategy = Strategy::query()->create([
            'agent_run_id' => $run->id,
            'symbol' => 'TSLA',
            'strategy_type' => StrategyType::BearPutSpread,
            'regime' => Direction::Bearish,
            'thesis' => $decision->thesis,
            'confidence' => '0.8100',
            'net_debit' => '4.2000',
            'net_credit' => null,
            'max_profit' => '5.8000',
            'max_loss' => '4.2000',
            'breakevens' => ['245.80'],
            'probability_of_profit' => '0.5500',
            'liquidity_score' => '0.8500',
            'score' => '0.7900',
            'score_breakdown' => ['thesis_confidence' => 0.81, 'liquidity' => 0.85, 'risk_reward' => 0.70],
            'quote_timestamp' => now()->subDays(5)->subMinutes(14),
            'dte' => 45,
            'rank' => 1,
            'selected' => true,
        ]);

        $decision->update(['strategy_id' => $strategy->id]);

        $legs = [
            ['action' => OrderSide::Buy, 'strike' => 250.0, 'intent' => PositionIntent::BuyToOpen, 'fill' => '8.30'],
            ['action' => OrderSide::Sell, 'strike' => 235.0, 'intent' => PositionIntent::SellToOpen, 'fill' => '8.10'],
        ];

        foreach ($legs as $leg) {
            StrategyLeg::query()->create([
                'strategy_id' => $strategy->id,
                'action' => $leg['action'],
                'occ_symbol' => $this->occSymbol('TSLA', $expiration, 'P', $leg['strike']),
                'underlying' => 'TSLA',
                'option_type' => OptionType::Put,
                'strike' => number_format($leg['strike'], 4, '.', ''),
                'expiration' => $expiration,
                'position_intent' => $leg['intent'],
                'ratio_qty' => 1,
                'quote_bid' => '8.10',
                'quote_ask' => '8.30',
                'delta' => '-0.480000',
                'gamma' => '0.014000',
                'theta' => '-0.062000',
                'vega' => '0.365000',
                'open_interest' => 6210,
                'volume' => 1520,
                'quote_time' => now()->subDays(5)->subMinutes(14),
            ]);
        }

        $proposal = TradeProposal::query()->create([
            'agent_run_id' => $run->id,
            'strategy_id' => $strategy->id,
            'user_id' => $this->user->id,
            'status' => TradeProposalStatus::Executed,
            'expires_at' => now()->subDays(5)->addHours(4),
            'approved_at' => now()->subDays(5)->subMinutes(10),
            'approved_by' => $this->user->id,
        ]);

        $evaluation = RiskEvaluation::query()->create([
            'trade_proposal_id' => $proposal->id,
            'risk_policy_id' => $this->riskPolicy->id,
            'risk_policy_version' => $this->riskPolicy->version,
            'result' => RiskResult::Approved,
            'risk_score' => '0.3300',
            'evaluated_at' => now()->subDays(5)->subMinutes(12),
        ]);

        RiskRuleResult::query()->create([
            'risk_evaluation_id' => $evaluation->id,
            'rule_key' => 'max_symbol_exposure_pct',
            'description' => 'Exposure to a single symbol must stay under the policy limit.',
            'observed_value' => '0.42',
            'limit_value' => '15.00',
            'passed' => true,
            'severity' => RiskSeverity::Info,
            'explanation' => 'TSLA exposure of $420 is 0.42% of equity, under the 15% single-symbol cap.',
        ]);

        $proposal->update(['risk_evaluation_id' => $evaluation->id]);

        $order = Order::query()->create([
            'trade_proposal_id' => $proposal->id,
            'broker_order_id' => 'demo-tsla-'.Str::random(8),
            'client_order_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => OrderStatus::Filled,
            'order_class' => 'mleg',
            'symbol' => 'TSLA',
            'qty' => 1,
            'submitted_via' => SubmissionChannel::Demo,
            'raw_request' => ['qty' => 1, 'strategy_id' => $strategy->id],
            'raw_response' => ['status' => 'filled'],
            'submitted_at' => now()->subDays(5)->subMinutes(9),
        ]);

        foreach ($legs as $leg) {
            OrderLeg::query()->create([
                'order_id' => $order->id,
                'occ_symbol' => $this->occSymbol('TSLA', $expiration, 'P', $leg['strike']),
                'side' => $leg['action'],
                'ratio_qty' => 1,
                'position_intent' => $leg['intent'],
                'filled_qty' => 1,
                'filled_avg_price' => $leg['fill'],
            ]);
        }

        Trade::query()->create([
            'order_id' => $order->id,
            'fill_id' => 'fill-tsla-'.Str::random(10),
            'qty' => 1,
            'price' => '4.2000',
            'side' => OrderSide::Buy,
            'filled_at' => now()->subDays(5)->subMinutes(9),
        ]);

        Position::query()->create([
            'user_id' => $this->user->id,
            'strategy_id' => $strategy->id,
            'symbol' => 'TSLA',
            'legs' => array_map(fn (array $leg) => [
                'occ_symbol' => $this->occSymbol('TSLA', $expiration, 'P', $leg['strike']),
                'side' => $leg['action']->value,
            ], $legs),
            'quantity' => 1,
            'avg_entry_price' => '4.2000',
            'current_value' => '5.8000',
            'unrealized_pl' => '0.0000',
            'status' => PositionStatus::Closed,
            'opened_at' => now()->subDays(5)->subMinutes(9),
            'closed_at' => now()->subDays(2),
        ]);
    }

    /**
     * A couple of still-open positions (no run attached — pre-existing
     * book positions), whose unrealized P&L sums to the headline
     * +$182.34 figure.
     */
    private function createOpenPositions(): void
    {
        Position::query()->create([
            'user_id' => $this->user->id,
            'strategy_id' => null,
            'symbol' => 'AAPL',
            'legs' => [
                ['occ_symbol' => $this->occSymbol('AAPL', now()->addDays(28)->toDateString(), 'C', 235), 'side' => 'sell'],
            ],
            'quantity' => -1,
            'avg_entry_price' => '3.1000',
            'current_value' => '2.1366',
            'unrealized_pl' => '96.3400',
            'status' => PositionStatus::Open,
            'opened_at' => now()->subDays(6),
            'closed_at' => null,
        ]);

        Position::query()->create([
            'user_id' => $this->user->id,
            'strategy_id' => null,
            'symbol' => 'MSFT',
            'legs' => [
                ['occ_symbol' => $this->occSymbol('MSFT', now()->addDays(35)->toDateString(), 'P', 410), 'side' => 'sell'],
            ],
            'quantity' => -1,
            'avg_entry_price' => '2.5000',
            'current_value' => '1.6400',
            'unrealized_pl' => '86.0000',
            'status' => PositionStatus::Open,
            'opened_at' => now()->subDays(4),
            'closed_at' => null,
        ]);
    }

    /**
     * ~2 weeks of history ending at the exact headline demo numbers, for
     * the dashboard equity curve.
     */
    private function createPortfolioSnapshots(): void
    {
        $startingEquity = 100000.00;
        $endingEquity = 100245.69;

        for ($i = 13; $i >= 1; $i--) {
            $progress = (13 - $i) / 13;
            $equity = $startingEquity + (($endingEquity - $startingEquity - 150) * $progress) + (sin($i) * 45);

            PortfolioSnapshot::query()->create([
                'user_id' => $this->user->id,
                'snapshot_at' => now()->subDays($i)->setTime(16, 0),
                'equity' => number_format($equity, 4, '.', ''),
                'cash' => number_format($equity - 3200, 4, '.', ''),
                'buying_power' => number_format(($equity - 3200) * 0.8, 4, '.', ''),
                'unrealized_pl' => number_format(max(0, $equity - $startingEquity - 63.35), 4, '.', ''),
                'realized_pl' => '63.3500',
                'options_exposure' => '3200.0000',
            ]);
        }

        // Final, authoritative snapshot matching the headline demo numbers
        // called out in the seeding brief.
        PortfolioSnapshot::query()->create([
            'user_id' => $this->user->id,
            'snapshot_at' => now(),
            'equity' => '100245.6900',
            'cash' => '96798.5500',
            'buying_power' => '78120.4500',
            'unrealized_pl' => '182.3400',
            'realized_pl' => '63.3500',
            'options_exposure' => '3447.1400',
        ]);
    }

    private function createBacktest(): void
    {
        $run = BacktestRun::query()->create([
            'user_id' => $this->user->id,
            'config' => [
                'symbols' => ['SPY', 'NVDA', 'TSLA'],
                'strategy_types' => ['bull_call_spread', 'iron_condor', 'bear_put_spread'],
                'start_date' => now()->subMonths(6)->toDateString(),
                'end_date' => now()->subDay()->toDateString(),
                'starting_equity' => 100000,
            ],
            'seed_hash' => hash('sha256', 'aura-demo-backtest-1'),
            'status' => BacktestStatus::Completed,
            'started_at' => now()->subDays(10),
            'completed_at' => now()->subDays(10)->addMinutes(4),
        ]);

        $equity = 100000.0;
        $equityCurve = [];

        for ($week = 0; $week <= 24; $week++) {
            $equity += 350 + (sin($week / 2) * 220);
            $equityCurve[] = [
                'date' => now()->subMonths(6)->addWeeks($week)->toDateString(),
                'equity' => round($equity, 2),
            ];
        }

        BacktestResult::query()->create([
            'backtest_run_id' => $run->id,
            'metrics' => [
                'total_return_pct' => 8.4,
                'win_rate' => 0.62,
                'profit_factor' => 1.9,
                'max_drawdown_pct' => 4.5,
                'sharpe_like' => 1.35,
                'trades' => 34,
            ],
            'equity_curve' => $equityCurve,
        ]);

        BacktestTrade::query()->create([
            'backtest_run_id' => $run->id,
            'entry_at' => now()->subMonths(5),
            'exit_at' => now()->subMonths(5)->addDays(18),
            'legs' => ['symbol' => 'SPY', 'strategy_type' => 'bull_call_spread'],
            'pnl' => '215.0000',
            'exit_reason' => 'target_profit_reached',
        ]);

        BacktestTrade::query()->create([
            'backtest_run_id' => $run->id,
            'entry_at' => now()->subMonths(3),
            'exit_at' => now()->subMonths(3)->addDays(12),
            'legs' => ['symbol' => 'NVDA', 'strategy_type' => 'iron_condor'],
            'pnl' => '-95.0000',
            'exit_reason' => 'stop_loss',
        ]);
    }

    private function createPerformanceReport(): void
    {
        PerformanceReport::query()->create([
            'user_id' => $this->user->id,
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'total_return' => '0.024569',
            'win_rate' => '0.6200',
            'profit_factor' => '1.8500',
            'max_drawdown' => '0.021300',
            'sharpe_like' => '1.2800',
            'generated_at' => now(),
        ]);
    }

    /**
     * Builds a (demo-quality) OCC option symbol: ROOT + YYMMDD + C/P +
     * strike * 1000, zero-padded to 8 digits.
     */
    private function occSymbol(string $underlying, string $expiration, string $type, float $strike): string
    {
        $date = Carbon::parse($expiration)->format('ymd');

        return sprintf('%s%s%s%08d', strtoupper($underlying), $date, $type, (int) round($strike * 1000));
    }
}
