<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trade_proposal_id')->constrained('trade_proposals')->cascadeOnDelete();
            $table->string('broker_order_id')->nullable()->unique();
            $table->string('client_order_id')->unique();
            $table->string('idempotency_key')->unique();
            $table->enum('status', [
                'new', 'accepted', 'partially_filled', 'filled', 'rejected',
                'cancelled', 'expired', 'unknown_reconciling',
            ])->default('new');
            $table->string('order_class');
            $table->string('symbol');
            $table->unsignedInteger('qty');
            $table->enum('submitted_via', ['alpaca_api', 'alpaca_mcp', 'alpaca_cli', 'demo']);
            $table->jsonb('raw_request')->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
