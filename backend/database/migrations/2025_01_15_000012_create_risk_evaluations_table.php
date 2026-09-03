<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trade_proposal_id')->constrained('trade_proposals')->cascadeOnDelete();
            $table->foreignUuid('risk_policy_id')->constrained('risk_policies');
            // Denormalized, immutable snapshot of the policy version in effect
            // at evaluation time, kept even if the policy is later superseded.
            $table->unsignedInteger('risk_policy_version');
            $table->enum('result', ['approved', 'approval_required', 'rejected']);
            $table->decimal('risk_score', 5, 4);
            $table->timestamp('evaluated_at');
            $table->timestamps();
        });

        // Now that risk_evaluations exists, wire the other half of the
        // trade_proposals <-> risk_evaluations relationship.
        Schema::table('trade_proposals', function (Blueprint $table) {
            $table->foreign('risk_evaluation_id')->references('id')->on('risk_evaluations')->nullOnDelete();
        });

        // At most one approved proposal per strategy. Created here, after the
        // foreign key above, because SQLite rebuilds the trade_proposals table
        // to add that constraint and does not preserve a raw partial index
        // created before the rebuild.
        DB::statement(
            "CREATE UNIQUE INDEX trade_proposals_one_approved_per_strategy ON trade_proposals (strategy_id) WHERE status = 'approved'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS trade_proposals_one_approved_per_strategy');

        Schema::table('trade_proposals', function (Blueprint $table) {
            $table->dropForeign(['risk_evaluation_id']);
        });

        Schema::dropIfExists('risk_evaluations');
    }
};
