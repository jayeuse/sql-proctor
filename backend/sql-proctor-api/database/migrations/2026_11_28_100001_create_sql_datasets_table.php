<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sql_datasets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->text('schema_sql');
            $table->text('seed_sql')->nullable();
            $table->jsonb('schema_summary')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'created_at']);
            $table->index('is_shared');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sql_datasets');
    }
};
