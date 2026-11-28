<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('exam_id')->constrained('exams')->restrictOnDelete();
            $table->integer('version_number');
            $table->jsonb('snapshot');
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');

            $table->unique(['exam_id', 'version_number']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exam_versions ADD CONSTRAINT exam_versions_number_check CHECK (version_number >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_versions');
    }
};
