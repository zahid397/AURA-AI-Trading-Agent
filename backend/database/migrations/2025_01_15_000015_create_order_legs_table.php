<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_legs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('occ_symbol');
            $table->enum('side', ['buy', 'sell']);
            $table->unsignedInteger('ratio_qty');
            $table->enum('position_intent', ['buy_to_open', 'buy_to_close', 'sell_to_open', 'sell_to_close']);
            $table->unsignedInteger('filled_qty')->default(0);
            $table->decimal('filled_avg_price', 18, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_legs');
    }
};
