<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            // id doubles as the LangGraph run_id shared with FastAPI.
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('trigger', ['manual', 'scheduled', 'approval_resume']);
            $table->enum('mode', ['demo', 'observe', 'approval_required', 'paper_autonomous']);
            $table->enum('status', ['pending', 'running', 'awaiting_approval', 'completed', 'failed', 'cancelled', 'rejected'])->default('pending');
            $table->uuid('trace_id');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('symbol_universe')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
