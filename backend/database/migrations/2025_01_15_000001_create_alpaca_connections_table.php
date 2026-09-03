<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alpaca_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->enum('environment', ['paper', 'demo'])->default('paper');
            $table->string('account_id')->nullable();
            $table->string('api_key_last4', 4)->nullable();
            $table->text('secret_ciphertext')->nullable();
            $table->enum('status', ['not_configured', 'connected', 'error'])->default('not_configured');
            $table->jsonb('capabilities')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alpaca_connections');
    }
};
