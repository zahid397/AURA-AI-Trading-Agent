<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->jsonb('metrics');
            $table->jsonb('equity_curve');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_results');
    }
};
