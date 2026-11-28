<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('examiner_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('join_code', 16)->unique();
            $table->boolean('is_open')->default(true);
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamps();

            $table->index(['examiner_id', 'created_at']);
            $table->index(['is_open', 'code_expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
