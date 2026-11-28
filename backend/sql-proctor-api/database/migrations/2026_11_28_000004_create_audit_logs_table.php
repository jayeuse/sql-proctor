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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('actor_type', 32);
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 120)->nullable();
            $table->string('event_type', 80)->index();
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['actor_type', 'actor_id', 'created_at'], 'audit_logs_actor_created_index');
            $table->index(['event_type', 'created_at'], 'audit_logs_event_created_index');
            $table->index(['target_type', 'target_id'], 'audit_logs_target_index');
            $table->index('created_at', 'audit_logs_created_at_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('admin', 'examiner', 'student', 'system', 'anonymous'))"
            );

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_audit_logs_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs is append-only; % operations are prohibited', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_logs_prevent_update_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW
                EXECUTE FUNCTION prevent_audit_logs_mutation();
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS audit_logs_prevent_update_delete ON audit_logs;
                DROP FUNCTION IF EXISTS prevent_audit_logs_mutation();
            SQL);
        }

        Schema::dropIfExists('audit_logs');
    }
};
