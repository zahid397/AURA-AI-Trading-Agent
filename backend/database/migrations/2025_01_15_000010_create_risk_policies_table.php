<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version')->unique();
            $table->string('name');
            $table->jsonb('limits');
            $table->boolean('active')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // At most one active policy at a time. A plain composite unique index
        // can't express "unique only when true", so this is a partial unique
        // index — standard SQL, supported by both SQLite (>=3.8) and Postgres.
        DB::statement(
            'CREATE UNIQUE INDEX risk_policies_one_active_unique ON risk_policies (active) WHERE active = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_policies');
    }
};
