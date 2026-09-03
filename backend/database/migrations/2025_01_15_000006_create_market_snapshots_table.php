<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: not part of the literal db-schema.md column list, added so a
            // snapshot can be traced back to the run that produced it.
            $table->foreignUuid('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->string('symbol');
            $table->timestamp('snapshot_at');
            $table->decimal('price', 18, 4);
            $table->unsignedBigInteger('volume');
            $table->jsonb('indicators')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'snapshot_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
    }
};
