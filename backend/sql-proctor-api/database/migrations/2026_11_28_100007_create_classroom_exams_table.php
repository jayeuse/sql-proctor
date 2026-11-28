<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->foreignUuid('exam_id')->constrained('exams')->restrictOnDelete();
            $table->foreignUuid('exam_version_id')->constrained('exam_versions')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->timestamp('results_released_at')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['classroom_id', 'exam_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_exams');
    }
};
