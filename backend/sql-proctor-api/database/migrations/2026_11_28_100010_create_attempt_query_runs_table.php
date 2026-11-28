<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempt_query_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->text('sql_text');
            $table->string('status', 24);
            $table->integer('row_count')->nullable();
            $table->integer('execution_ms');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');

            $table->index(['attempt_id', 'question_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attempt_query_runs ADD CONSTRAINT aqr_status_check CHECK (status IN ('succeeded', 'sql_error', 'timeout', 'policy_blocked', 'system_error'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_query_runs');
    }
};
