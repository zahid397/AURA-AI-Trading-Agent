<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->enum('agent_type', ['market_analyst', 'options_strategy', 'risk_guardian', 'execution', 'learning']);
            $table->enum('level', ['debug', 'info', 'warn', 'error'])->default('info');
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestamps();

            $table->index(['agent_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_logs');
    }
};
