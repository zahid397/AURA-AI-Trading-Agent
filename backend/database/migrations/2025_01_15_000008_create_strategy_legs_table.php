<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_legs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('strategy_id')->constrained('strategies')->cascadeOnDelete();
            $table->enum('action', ['buy', 'sell']);
            $table->string('occ_symbol');
            $table->string('underlying');
            $table->enum('option_type', ['call', 'put']);
            $table->decimal('strike', 18, 4);
            $table->date('expiration');
            $table->enum('position_intent', ['buy_to_open', 'buy_to_close', 'sell_to_open', 'sell_to_close']);
            $table->unsignedInteger('ratio_qty');
            $table->decimal('quote_bid', 18, 4);
            $table->decimal('quote_ask', 18, 4);
            $table->decimal('delta', 9, 6)->nullable();
            $table->decimal('gamma', 9, 6)->nullable();
            $table->decimal('theta', 9, 6)->nullable();
            $table->decimal('vega', 9, 6)->nullable();
            $table->unsignedInteger('open_interest')->nullable();
            $table->unsignedInteger('volume')->nullable();
            $table->timestamp('quote_time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_legs');
    }
};
