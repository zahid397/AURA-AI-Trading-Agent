<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->foreignUuid('strategy_id')->nullable()->constrained('strategies')->nullOnDelete();
            $table->text('thesis');
            $table->jsonb('evidence');
            $table->decimal('confidence', 5, 4);
            $table->string('model_provider');
            $table->string('model_name');
            $table->string('prompt_version');
            $table->jsonb('feature_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decisions');
    }
};
