<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('snapshot_at');
            $table->decimal('equity', 18, 4);
            $table->decimal('cash', 18, 4);
            $table->decimal('buying_power', 18, 4);
            $table->decimal('unrealized_pl', 18, 4);
            $table->decimal('realized_pl', 18, 4);
            $table->decimal('options_exposure', 18, 4);
            $table->timestamps();

            $table->index(['user_id', 'snapshot_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_snapshots');
    }
};
