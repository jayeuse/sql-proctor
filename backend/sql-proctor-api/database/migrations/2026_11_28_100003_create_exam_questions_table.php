<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->integer('position');
            $table->string('question_type', 24);
            $table->string('grading_mode', 16);
            $table->string('title', 160);
            $table->text('prompt');
            $table->decimal('points', 6, 2);
            $table->text('starter_sql')->nullable();
            $table->text('reference_sql')->nullable();
            $table->jsonb('comparison_config')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('rubric')->default(DB::raw("'[]'::jsonb"));
            $table->timestamps();

            $table->unique(['exam_id', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exam_questions ADD CONSTRAINT exam_questions_position_check CHECK (position >= 1)');
            DB::statement("ALTER TABLE exam_questions ADD CONSTRAINT exam_questions_type_check CHECK (question_type IN ('sql_select', 'sql_dml', 'sql_ddl', 'sql_debug', 'short_answer', 'mcq'))");
            DB::statement("ALTER TABLE exam_questions ADD CONSTRAINT exam_questions_grading_mode_check CHECK (grading_mode IN ('auto', 'manual', 'hybrid'))");
            DB::statement('ALTER TABLE exam_questions ADD CONSTRAINT exam_questions_points_check CHECK (points > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};
