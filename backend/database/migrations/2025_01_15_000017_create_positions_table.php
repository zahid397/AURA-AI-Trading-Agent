<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('strategy_id')->nullable()->constrained('strategies')->nullOnDelete();
            $table->string('symbol');
            $table->jsonb('legs');
            $table->integer('quantity');
            $table->decimal('avg_entry_price', 18, 4);
            $table->decimal('current_value', 18, 4);
            $table->decimal('unrealized_pl', 18, 4);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
