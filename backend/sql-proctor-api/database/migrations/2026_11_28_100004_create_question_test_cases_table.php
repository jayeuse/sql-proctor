<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_test_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->string('name', 120);
            $table->integer('position');
            $table->decimal('weight_points', 6, 2);
            $table->boolean('is_hidden')->default(false);
            $table->text('override_seed_sql')->nullable();
            $table->text('assertion_sql')->nullable();
            $table->string('expected_behavior', 24)->default('match_reference');
            $table->timestamps();

            $table->unique(['question_id', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE question_test_cases ADD CONSTRAINT qtc_position_check CHECK (position >= 1)');
            DB::statement('ALTER TABLE question_test_cases ADD CONSTRAINT qtc_weight_points_check CHECK (weight_points > 0)');
            DB::statement("ALTER TABLE question_test_cases ADD CONSTRAINT qtc_expected_behavior_check CHECK (expected_behavior IN ('match_reference', 'expect_error', 'non_empty'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('question_test_cases');
    }
};
