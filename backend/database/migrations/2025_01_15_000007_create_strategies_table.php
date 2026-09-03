<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->string('symbol');
            $table->enum('strategy_type', [
                'bull_call_spread',
                'bear_put_spread',
                'iron_condor',
                'covered_call',
                'cash_secured_put',
            ]);
            $table->enum('regime', ['bullish', 'bearish', 'neutral']);
            $table->text('thesis');
            $table->decimal('confidence', 5, 4);
            $table->decimal('net_debit', 18, 4)->nullable();
            $table->decimal('net_credit', 18, 4)->nullable();
            $table->decimal('max_profit', 18, 4);
            $table->decimal('max_loss', 18, 4);
            $table->jsonb('breakevens');
            $table->decimal('probability_of_profit', 5, 4);
            $table->decimal('liquidity_score', 5, 4);
            $table->decimal('score', 5, 4);
            $table->jsonb('score_breakdown');
            $table->timestamp('quote_timestamp');
            $table->unsignedInteger('dte');
            $table->unsignedInteger('rank')->nullable();
            $table->boolean('selected')->default(false);
            $table->timestamps();

            $table->index(['symbol', 'strategy_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategies');
    }
};
