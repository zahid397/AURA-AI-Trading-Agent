<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AgentRunController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\BacktestController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DecisionController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\RiskController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\StrategyController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\TradeProposalController;
use App\Http\Controllers\Internal\V1\EventsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API v1 routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:register')
        ->name('auth.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    /*
    |----------------------------------------------------------------------
    | Sanctum-protected API v1 routes
    |----------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('summary', [DashboardController::class, 'summary'])->name('summary');
            Route::get('equity-curve', [DashboardController::class, 'equityCurve'])->name('equity-curve');
            Route::get('activity', [DashboardController::class, 'activity'])->name('activity');
        });

        Route::prefix('agents')->name('agents.')->group(function () {
            Route::get('/', [AgentController::class, 'index'])->name('index');
            Route::get('{agent}', [AgentController::class, 'show'])->name('show');
            Route::patch('{agent}/state', [AgentController::class, 'updateState'])->name('update-state');
            Route::get('{agent}/logs', [AgentController::class, 'logs'])->name('logs');
        });

        Route::prefix('agent-runs')->name('agent-runs.')->group(function () {
            Route::post('/', [AgentRunController::class, 'store'])->name('store');
            Route::get('{agentRun}', [AgentRunController::class, 'show'])->name('show');
            Route::post('{agentRun}/cancel', [AgentRunController::class, 'cancel'])->name('cancel');
        });

        Route::prefix('risk')->name('risk.')->group(function () {
            Route::get('overview', [RiskController::class, 'overview'])->name('overview');
            Route::get('policy', [RiskController::class, 'showPolicy'])->name('policy.show');
            Route::patch('policy', [RiskController::class, 'updatePolicy'])->name('policy.update');
            Route::get('alerts', [RiskController::class, 'alerts'])->name('alerts');
            Route::post('kill-switch', [RiskController::class, 'killSwitch'])->name('kill-switch');
        });

        Route::prefix('portfolio')->name('portfolio.')->group(function () {
            Route::get('summary', [PortfolioController::class, 'summary'])->name('summary');
            Route::get('positions', [PortfolioController::class, 'positions'])->name('positions');
            Route::get('allocation', [PortfolioController::class, 'allocation'])->name('allocation');
            Route::get('performance', [PortfolioController::class, 'performance'])->name('performance');
        });

        Route::prefix('trade-proposals')->name('trade-proposals.')->group(function () {
            Route::get('/', [TradeProposalController::class, 'index'])->name('index');
            Route::get('{tradeProposal}', [TradeProposalController::class, 'show'])->name('show');
            Route::post('{tradeProposal}/approve', [TradeProposalController::class, 'approve'])->name('approve');
            Route::post('{tradeProposal}/reject', [TradeProposalController::class, 'reject'])->name('reject');
            Route::post('{tradeProposal}/execute', [TradeProposalController::class, 'execute'])->name('execute');
        });

        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('{order}', [OrderController::class, 'show'])->name('show');
            Route::post('{order}/cancel', [OrderController::class, 'cancel'])->name('cancel');
        });

        Route::prefix('decisions')->name('decisions.')->group(function () {
            Route::get('/', [DecisionController::class, 'index'])->name('index');
            Route::get('{decision}', [DecisionController::class, 'show'])->name('show');
            Route::get('{decision}/trace', [DecisionController::class, 'trace'])->name('trace');
        });

        // Ranked/scored candidate strategies produced by a scan (the
        // market_analyst + options_strategy agents) — there is no
        // separate "opportunities" table in the schema; strategies.rank
        // and .score already model this. See StrategyController.
        Route::prefix('strategies')->name('strategies.')->group(function () {
            Route::get('/', [StrategyController::class, 'index'])->name('index');
            Route::get('{strategy}', [StrategyController::class, 'show'])->name('show');
        });

        Route::prefix('backtests')->name('backtests.')->group(function () {
            Route::get('/', [BacktestController::class, 'index'])->name('index');
            Route::post('/', [BacktestController::class, 'store'])->name('store');
            Route::get('{backtestRun}', [BacktestController::class, 'show'])->name('show');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingsController::class, 'show'])->name('show');
            Route::patch('/', [SettingsController::class, 'update'])->name('update');
            Route::post('alpaca/test', [SettingsController::class, 'testAlpaca'])->name('alpaca-test');
            Route::post('ai-provider/test', [SettingsController::class, 'testAiProvider'])->name('ai-provider-test');
        });

        Route::prefix('system')->name('system.')->group(function () {
            Route::get('health', [SystemController::class, 'health'])->name('health');
            Route::get('capabilities', [SystemController::class, 'capabilities'])->name('capabilities');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Internal service-to-service API (FastAPI ai-engine -> Laravel)
|--------------------------------------------------------------------------
|
| Not behind Sanctum — this is a machine-to-machine call authenticated by
| an HMAC-SHA256 signature over the raw body (see
| App\Http\Middleware\VerifyInternalSignature and docs/architecture.md).
|
*/

Route::post('internal/v1/events', [EventsController::class, 'store'])
    ->middleware('internal.signature')
    ->name('internal.v1.events.store');
