<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completes the backtest results pipeline: the ai-engine's trade payload
     * has always carried a `symbol` per trade (and the frontend's
     * `BacktestTrade` type has always expected one), but the table never had
     * a column for it. The three new `backtest_results` columns hold the
     * derived views the frontend renders (drawdown curve, return
     * distribution, monthly returns) that the ai-engine's raw result doesn't
     * provide directly -- computed once at persist time and stored
     * alongside `metrics`/`equity_curve` rather than recomputed per request.
     */
    public function up(): void
    {
        Schema::table('backtest_trades', function (Blueprint $table) {
            $table->string('symbol')->nullable()->after('backtest_run_id');
        });

        Schema::table('backtest_results', function (Blueprint $table) {
            $table->jsonb('drawdown_curve')->nullable()->after('equity_curve');
            $table->jsonb('return_distribution')->nullable()->after('drawdown_curve');
            $table->jsonb('monthly_returns')->nullable()->after('return_distribution');
        });
    }

    public function down(): void
    {
        Schema::table('backtest_trades', function (Blueprint $table) {
            $table->dropColumn('symbol');
        });

        Schema::table('backtest_results', function (Blueprint $table) {
            $table->dropColumn(['drawdown_curve', 'return_distribution', 'monthly_returns']);
        });
    }
};
