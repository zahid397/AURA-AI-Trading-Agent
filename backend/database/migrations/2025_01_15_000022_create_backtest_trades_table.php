<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_trades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->timestamp('entry_at');
            $table->timestamp('exit_at');
            $table->jsonb('legs');
            $table->decimal('pnl', 18, 4);
            $table->string('exit_reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_trades');
    }
};
