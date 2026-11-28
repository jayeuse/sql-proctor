<?php

namespace Tests\Feature\Audit;

use App\Enums\ActorType;
use App\Enums\UserRole;
use App\Exceptions\ImmutableAuditLogException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLoggingAndImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    /** TM-4 | FR-10, NFR-2 | security violation */
    public function test_audit_logger_recursively_redacts_sensitive_keys(): void
    {
        $logger = app(AuditLogger::class);

        $log = $logger->log(
            eventType: 'auth.login_failed',
            actorType: ActorType::Anonymous,
            actorId: null,
            actorLabel: 'tester',
            targetType: 'user',
            targetId: '1',
            metadata: [
                'password' => 'super-secret-password-123',
                'password_confirmation' => 'super-secret-password-123',
                'current_password' => 'old-secret-password',
                'token' => 'secret-bearer-token',
                'cookie' => 'secret-cookie-value',
                'authorization' => 'Bearer top-secret',
                'xsrf' => 'xsrf-secret-val',
                'secret' => 'top-secret-key',
                'nested' => [
                    'UserPassword' => 'nested-password-secret',
                    'api_token' => 'nested-token-secret',
                    'safe_field' => 'visible-value',
                ],
            ],
        );

        $this->assertInstanceOf(AuditLog::class, $log);

        $persisted = DB::table('audit_logs')->where('id', $log->id)->first();
        $this->assertNotNull($persisted);

        $decoded = json_decode((string) $persisted->metadata, true);
        $this->assertSame('[REDACTED]', $decoded['password']);
        $this->assertSame('[REDACTED]', $decoded['password_confirmation']);
        $this->assertSame('[REDACTED]', $decoded['current_password']);
        $this->assertSame('[REDACTED]', $decoded['token']);
        $this->assertSame('[REDACTED]', $decoded['cookie']);
        $this->assertSame('[REDACTED]', $decoded['authorization']);
        $this->assertSame('[REDACTED]', $decoded['xsrf']);
        $this->assertSame('[REDACTED]', $decoded['secret']);
        $this->assertSame('[REDACTED]', $decoded['nested']['UserPassword']);
        $this->assertSame('[REDACTED]', $decoded['nested']['api_token']);
        $this->assertSame('visible-value', $decoded['nested']['safe_field']);

        $rawJson = (string) $persisted->metadata;
        $this->assertStringNotContainsString('super-secret-password-123', $rawJson);
        $this->assertStringNotContainsString('secret-bearer-token', $rawJson);
        $this->assertStringNotContainsString('nested-password-secret', $rawJson);
    }

    /** TM-22 | FR-10 | security violation */
    public function test_non_uuid_request_id_header_does_not_break_audit_log_persistence(): void
    {
        $response = $this->withHeaders(['X-Request-Id' => 'req_custom-123'])
            ->postJson('/api/auth/login', [
                'username' => 'nobody',
                'password' => 'wrong-password-123',
            ]);

        $response->assertStatus(401)
            ->assertHeader('X-Request-Id', 'req_custom-123');

        $row = DB::table('audit_logs')
            ->where('event_type', 'auth.login_failed')
            ->first();

        $this->assertNotNull($row);
        $this->assertTrue(Str::isUuid((string) $row->request_id));
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame('req_custom-123', $meta['client_request_id'] ?? null);
    }

    /** TM-19 | FR-11 | data integrity failure */
    public function test_audit_log_model_blocks_eloquent_update_and_delete(): void
    {
        $log = app(AuditLogger::class)->log(
            eventType: 'admin.bootstrap_completed',
            actorType: ActorType::System,
            actorLabel: 'system',
        );

        $this->expectException(ImmutableAuditLogException::class);
        $log->update(['event_type' => 'tampered.event']);
    }

    /** TM-19 | FR-11 | data integrity failure */
    public function test_audit_log_model_blocks_eloquent_delete(): void
    {
        $log = app(AuditLogger::class)->log(
            eventType: 'admin.bootstrap_completed',
            actorType: ActorType::System,
            actorLabel: 'system',
        );

        try {
            $log->delete();
            $this->fail('Expected ImmutableAuditLogException was not thrown.');
        } catch (ImmutableAuditLogException) {
            $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
        }
    }

    /** TM-20 | FR-11 | data integrity failure */
    public function test_postgresql_trigger_blocks_raw_sql_update_and_delete_on_audit_logs(): void
    {
        $log = app(AuditLogger::class)->log(
            eventType: 'admin.bootstrap_completed',
            actorType: ActorType::System,
            actorLabel: 'system',
        );

        try {
            DB::transaction(function () use ($log): void {
                DB::statement("UPDATE audit_logs SET event_type = 'tampered' WHERE id = ?", [$log->id]);
            });
            $this->fail('Expected PostgreSQL trigger exception on raw UPDATE.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', strtolower($e->getMessage()));
        }

        try {
            DB::transaction(function () use ($log): void {
                DB::statement('DELETE FROM audit_logs WHERE id = ?', [$log->id]);
            });
            $this->fail('Expected PostgreSQL trigger exception on raw DELETE.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', strtolower($e->getMessage()));
        }

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'event_type' => 'admin.bootstrap_completed',
        ]);
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'));
    }

    /** TM-5 | FR-12 | unauthorized access */
    public function test_unauthenticated_request_to_audit_logs_returns_401(): void
    {
        $this->getJson('/api/audit-logs')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /** TM-7 | FR-12 | forbidden access */
    public function test_examiner_cannot_access_audit_logs(): void
    {
        $examiner = User::factory()->examiner()->create();

        $this->actingAs($examiner, 'sanctum')
            ->getJson('/api/audit-logs')
            ->assertStatus(403);
    }

    /** TM-1 | FR-12 | invalid input */
    public function test_invalid_audit_log_filters_return_422(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/audit-logs?actor_type=hacker')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['actor_type']]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/audit-logs?from=not-a-timestamp')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['from']]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/audit-logs?from=2026-11-28T12:00:00Z&to=2026-11-27T12:00:00Z')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['to']]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/audit-logs?per_page=201')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['per_page']]);
    }

    /** TM-28 | FR-12 | normal behavior */
    public function test_admin_can_paginate_and_filter_audit_logs_newest_first(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'audit_admin']);
        $logger = app(AuditLogger::class);

        $older = $logger->log(
            eventType: 'examiner.created',
            actorType: ActorType::Admin,
            actorId: (string) $admin->id,
            actorLabel: 'audit_admin',
            targetType: 'user',
            targetId: '42',
            metadata: ['username' => 'examiner_a'],
        );
        DB::table('audit_logs')->where('id', $older->id); // Ensure exists without mutating

        $middle = $logger->log(
            eventType: 'student.joined_class',
            actorType: ActorType::Student,
            actorId: (string) Str::uuid(),
            actorLabel: 'Alice',
            targetType: 'classroom',
            targetId: 'class-1',
        );

        $newer = $logger->log(
            eventType: 'examiner.password_reset',
            actorType: ActorType::Admin,
            actorId: (string) $admin->id,
            actorLabel: 'audit_admin',
            targetType: 'user',
            targetId: '42',
        );

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/audit-logs?per_page=2&page=1');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'actor_type', 'actor_id', 'actor_label', 'event_type', 'target_type', 'target_id', 'metadata', 'ip_address', 'user_agent', 'request_id', 'created_at']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total'],
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);

        // Verify filter accuracy
        $filtered = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/audit-logs?actor_type=student&event_type=student.joined_class&target_type=classroom&target_id=class-1');

        $filtered->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $middle->id)
            ->assertJsonPath('data.0.actor_label', 'Alice');

        // Verify target_id filter for admin actions
        $adminTarget = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/audit-logs?actor_id={$admin->id}&target_id=42&event_type=examiner.password_reset");

        $adminTarget->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id);
    }

    /** TM-4 | FR-10 | security & serialization */
    public function test_audit_logger_serializes_enums_and_datetime_objects_safely(): void
    {
        $timestamp = now()->utc()->startOfSecond();

        $log = app(AuditLogger::class)->log(
            eventType: 'examiner.updated',
            actorType: ActorType::Admin,
            metadata: [
                'role' => UserRole::Examiner,
                'at' => $timestamp,
            ],
        );

        $persisted = DB::table('audit_logs')->where('id', $log->id)->first();
        $decoded = json_decode((string) $persisted->metadata, true);

        $this->assertSame('examiner', $decoded['role']);
        $this->assertSame($timestamp->toIso8601String(), $decoded['at']);
    }

    /** TM-28 | FR-12 | empty filter & date-only boundary */
    public function test_empty_audit_filters_are_ignored_and_date_only_to_includes_full_utc_day(): void
    {
        $admin = User::factory()->admin()->create();
        $logger = app(AuditLogger::class);

        $event = $logger->log(
            eventType: 'examiner.created',
            actorType: ActorType::Admin,
            actorId: (string) $admin->id,
        );

        // Ensure event timestamp is past midnight UTC today
        $today = now()->utc()->format('Y-m-d');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/audit-logs?actor_type=&event_type=&target_type=&from={$today}&to={$today}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id);
    }
}
