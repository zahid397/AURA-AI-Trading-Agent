<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_rule_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('risk_evaluation_id')->constrained('risk_evaluations')->cascadeOnDelete();
            $table->string('rule_key');
            $table->string('description');
            $table->string('observed_value');
            $table->string('limit_value');
            $table->boolean('passed');
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->text('explanation');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_rule_results');
    }
};
