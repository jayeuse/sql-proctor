<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('examiner_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('dataset_id')->nullable()->constrained('sql_datasets')->restrictOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status', 24)->default('draft');
            $table->integer('duration_minutes');
            $table->decimal('passing_score_percent', 5, 2)->default(60.00);
            $table->decimal('total_points', 7, 2)->default(0.00);
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->integer('current_version_number')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['examiner_id', 'status']);
            $table->index(['status', 'published_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE exams ADD CONSTRAINT exams_status_check CHECK (status IN ('draft', 'published', 'unpublished', 'archived'))");
            DB::statement('ALTER TABLE exams ADD CONSTRAINT exams_duration_minutes_check CHECK (duration_minutes > 0 AND duration_minutes <= 480)');
            DB::statement('ALTER TABLE exams ADD CONSTRAINT exams_passing_score_percent_check CHECK (passing_score_percent >= 0 AND passing_score_percent <= 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
