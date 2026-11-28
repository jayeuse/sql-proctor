<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('classroom_exam_id')->constrained('classroom_exams')->restrictOnDelete();
            $table->foreignUuid('exam_version_id')->constrained('exam_versions')->restrictOnDelete();
            $table->foreignUuid('student_session_id')->constrained('student_sessions')->restrictOnDelete();
            $table->string('status', 24);
            $table->string('grading_status', 24)->default('pending');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->jsonb('question_order');
            $table->decimal('auto_score', 7, 2)->default(0.00);
            $table->decimal('manual_score', 7, 2)->nullable();
            $table->decimal('final_score', 7, 2)->default(0.00);
            $table->decimal('max_score', 7, 2);
            $table->boolean('passed')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['classroom_exam_id', 'student_session_id']);
            $table->index(['classroom_exam_id', 'status']);
            $table->index('grading_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE exam_attempts ADD CONSTRAINT exam_attempts_status_check CHECK (status IN ('in_progress', 'submitted', 'auto_submitted', 'force_closed'))");
            DB::statement("ALTER TABLE exam_attempts ADD CONSTRAINT exam_attempts_grading_status_check CHECK (grading_status IN ('pending', 'auto_graded', 'needs_review', 'finalized'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
