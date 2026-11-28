<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->text('submitted_sql')->nullable();
            $table->text('submitted_text')->nullable();
            $table->jsonb('selected_option_ids')->nullable();
            $table->decimal('auto_score', 6, 2)->nullable();
            $table->decimal('manual_score', 6, 2)->nullable();
            $table->decimal('final_score', 6, 2)->default(0.00);
            $table->decimal('max_points', 6, 2);
            $table->string('grading_status', 24);
            $table->jsonb('test_results')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('rubric_scores')->nullable();
            $table->text('examiner_feedback')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attempt_answers ADD CONSTRAINT attempt_answers_grading_status_check CHECK (grading_status IN ('unanswered', 'auto_graded', 'needs_review', 'graded'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_answers');
    }
};
