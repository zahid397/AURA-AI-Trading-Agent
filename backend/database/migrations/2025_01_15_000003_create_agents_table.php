<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['market_analyst', 'options_strategy', 'risk_guardian', 'execution', 'learning'])->unique();
            $table->boolean('enabled')->default(true);
            $table->enum('status', ['idle', 'running', 'paused', 'error'])->default('idle');
            $table->unsignedInteger('config_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
