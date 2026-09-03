<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->foreignUuid('strategy_id')->constrained('strategies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired', 'executed'])->default('pending');
            // Soft reference to risk_evaluations.id. risk_evaluations itself has a
            // hard FK back to this table, so this side is left as an indexed
            // column (no DB constraint) to avoid a circular foreign key between
            // the two tables at creation time.
            $table->uuid('risk_evaluation_id')->nullable()->index();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // The "at most one approved proposal per strategy" partial unique index
        // is created in the next migration, after the risk_evaluation_id
        // foreign key is added below — SQLite rebuilds this table to add that
        // constraint, which does not reliably preserve a raw partial index
        // created here (verified: the WHERE clause was silently dropped when
        // both were in the same migration run).
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_proposals');
    }
};
