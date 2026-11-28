<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->string('display_name', 64);
            $table->string('normalized_display_name', 64);
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->index(['classroom_id', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX student_sessions_classroom_active_name_unique ON student_sessions (classroom_id, normalized_display_name) WHERE revoked_at IS NULL'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS student_sessions_classroom_active_name_unique');
        }

        Schema::dropIfExists('student_sessions');
    }
};
