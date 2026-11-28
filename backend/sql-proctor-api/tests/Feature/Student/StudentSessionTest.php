<?php

namespace Tests\Feature\Student;

use App\Models\Classroom;
use App\Models\StudentSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentSessionTest extends TestCase
{
    use RefreshDatabase;

    /** TM-1 | FR-8 | invalid input */
    public function test_student_join_rejects_invalid_display_name_and_class_code(): void
    {
        $this->postJson('/api/student/join', [
            'display_name' => '<script>alert(1)</script>',
            'class_code' => 'bad',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['display_name', 'class_code']]);
    }

    /** TM-5 | FR-9 | unauthorized access */
    public function test_unauthenticated_student_session_and_leave_return_401(): void
    {
        $this->getJson('/api/student/session')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->postJson('/api/student/leave')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /** TM-3 | FR-9 | security violation */
    public function test_tampered_student_session_cookie_is_rejected_with_401(): void
    {
        $this->withCredentials()
            ->withUnencryptedCookie('student_session_id', 'tampered-not-encrypted-cookie')
            ->getJson('/api/student/session')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /** TM-3 | FR-8, FR-9, NFR-3 | security violation */
    public function test_student_join_and_leave_reject_missing_csrf_token_even_from_untrusted_origins(): void
    {
        $examiner = User::factory()->examiner()->create();
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create([
            'join_code' => 'CSRF2345',
            'is_open' => true,
        ]);

        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        // External untrusted origin POST without CSRF fails 419
        $this->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/student/join', [
                'display_name' => 'Mallory',
                'class_code' => 'CSRF2345',
            ])
            ->assertStatus(419)
            ->assertExactJson(['message' => 'CSRF token mismatch.']);

        // Same-site cross-port POST to leave without CSRF fails 419
        $session = StudentSession::factory()->for($classroom)->create();
        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:9999')
            ->withCookie('student_session_id', $session->id)
            ->postJson('/api/student/leave')
            ->assertStatus(419)
            ->assertExactJson(['message' => 'CSRF token mismatch.']);

        $this->assertNull($session->fresh()->revoked_at);
    }

    /** TM-12 | FR-8 | missing resource */
    public function test_student_join_with_unknown_class_code_returns_404_and_audits_failure(): void
    {
        $this->postJson('/api/student/join', [
            'display_name' => 'Student One',
            'class_code' => 'ZZZZ9999',
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'class_not_found');

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'student.join_failed',
            'actor_type' => 'anonymous',
        ]);
    }

    /** TM-10 | FR-8, FR-9 | invalid state transition */
    public function test_student_join_with_closed_or_expired_class_fails_and_closed_class_blocks_active_session(): void
    {
        $examiner = User::factory()->examiner()->create();
        $closed = Classroom::factory()->for($examiner, 'examiner')->closed()->create([
            'join_code' => 'ABCD2345',
        ]);
        $expired = Classroom::factory()->for($examiner, 'examiner')->expired()->create([
            'join_code' => 'EFGH6789',
        ]);

        $this->postJson('/api/student/join', [
            'display_name' => 'Juan',
            'class_code' => $closed->join_code,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['class_code']]);

        $this->postJson('/api/student/join', [
            'display_name' => 'Juan',
            'class_code' => $expired->join_code,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['class_code']]);

        $this->assertSame(2, DB::table('audit_logs')->where('event_type', 'student.join_failed')->count());

        // Active student session blocked once classroom closes
        $openClass = Classroom::factory()->for($examiner, 'examiner')->create([
            'join_code' => 'OPEN2345',
            'is_open' => true,
        ]);
        $join = $this->postJson('/api/student/join', [
            'display_name' => 'Maria',
            'class_code' => 'OPEN2345',
        ]);
        $join->assertStatus(201);
        $cookie = $join->getCookie('student_session_id', false);
        $this->assertNotNull($cookie);

        $openClass->update(['is_open' => false]);

        $this->withCredentials()
            ->withUnencryptedCookie('student_session_id', $cookie->getValue())
            ->getJson('/api/student/session')
            ->assertStatus(403)
            ->assertJsonPath('code', 'class_closed');

        // Student can still cleanly leave & clear session cookie when classroom closes
        $leaveAfterClose = $this->withCredentials()
            ->withUnencryptedCookie('student_session_id', $cookie->getValue())
            ->postJson('/api/student/leave');

        $leaveAfterClose->assertOk()
            ->assertExactJson(['message' => 'Left class.']);

        $expiredCookie = $leaveAfterClose->getCookie('student_session_id', false);
        $this->assertNotNull($expiredCookie);
        $this->assertLessThan(time(), $expiredCookie->getExpiresTime());
        $this->assertNotNull(StudentSession::find($join->json('data.id'))->revoked_at);
    }

    /** TM-13, TM-27 | FR-8 | session replacement */
    public function test_rejoining_another_classroom_with_existing_cookie_revokes_superseded_session(): void
    {
        $examiner = User::factory()->examiner()->create();
        $classA = Classroom::factory()->for($examiner, 'examiner')->create(['join_code' => 'CLSA2345']);
        $classB = Classroom::factory()->for($examiner, 'examiner')->create(['join_code' => 'CLSB6789']);

        $joinA = $this->postJson('/api/student/join', [
            'display_name' => 'Grace Hopper',
            'class_code' => 'CLSA2345',
        ])->assertStatus(201);

        $cookieA = $joinA->getCookie('student_session_id', false);
        $this->assertNotNull($cookieA);
        $sessionAId = $joinA->json('data.id');

        $joinB = $this->withCredentials()
            ->withUnencryptedCookie('student_session_id', $cookieA->getValue())
            ->postJson('/api/student/join', [
                'display_name' => 'Grace Hopper',
                'class_code' => 'CLSB6789',
            ])->assertStatus(201);

        $this->assertNotSame($sessionAId, $joinB->json('data.id'));
        $this->assertNotNull(StudentSession::findOrFail($sessionAId)->revoked_at);
        $this->assertSame(0, $classA->activeStudentSessions()->count());
        $this->assertSame(1, $classB->activeStudentSessions()->count());
    }

    /** TM-3, TM-27 | FR-8, FR-9, NFR-3 | end-to-end CSRF flow */
    public function test_student_csrf_flow_works_with_csrf_validation_enabled(): void
    {
        $examiner = User::factory()->examiner()->create();
        Classroom::factory()->for($examiner, 'examiner')->create(['join_code' => 'REAL2345']);

        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $csrfResponse = $this->withHeader('Origin', 'http://localhost:3000')
            ->get('/sanctum/csrf-cookie');
        $csrfResponse->assertNoContent();

        $rawXsrfCookie = $csrfResponse->getCookie('XSRF-TOKEN', false);
        $rawSessionCookie = $csrfResponse->getCookie(config('session.cookie'), false);
        $this->assertNotNull($rawXsrfCookie);
        $this->assertNotNull($rawSessionCookie);

        // Axios reads XSRF-TOKEN cookie string (encrypted by Laravel) and sends it in X-XSRF-TOKEN
        $join = $this->withCredentials()
            ->withHeaders([
                'Origin' => 'http://localhost:3000',
                'X-XSRF-TOKEN' => $rawXsrfCookie->getValue(),
            ])
            ->withUnencryptedCookie(config('session.cookie'), $rawSessionCookie->getValue())
            ->withUnencryptedCookie('XSRF-TOKEN', $rawXsrfCookie->getValue())
            ->postJson('/api/student/join', [
                'display_name' => 'Real Student',
                'class_code' => 'REAL2345',
            ]);

        $join->assertStatus(201);
        $studentCookie = $join->getCookie('student_session_id', false);
        $this->assertNotNull($studentCookie);

        $this->withCredentials()
            ->withHeaders([
                'Origin' => 'http://localhost:3000',
                'X-XSRF-TOKEN' => $rawXsrfCookie->getValue(),
            ])
            ->withUnencryptedCookie(config('session.cookie'), $rawSessionCookie->getValue())
            ->withUnencryptedCookie('XSRF-TOKEN', $rawXsrfCookie->getValue())
            ->withUnencryptedCookie('student_session_id', $studentCookie->getValue())
            ->postJson('/api/student/leave')
            ->assertOk()
            ->assertExactJson(['message' => 'Left class.']);
    }

    /** TM-11 | FR-9 | invalid state transition */
    public function test_expired_or_revoked_student_session_cookie_is_rejected_with_401(): void
    {
        $examiner = User::factory()->examiner()->create();
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create();

        $expiredSession = StudentSession::factory()->for($classroom)->expired()->create();
        $revokedSession = StudentSession::factory()->for($classroom)->revoked()->create();

        $this->withCredentials()
            ->withCookie('student_session_id', $expiredSession->id)
            ->getJson('/api/student/session')
            ->assertStatus(401);

        $this->withCredentials()
            ->withCookie('student_session_id', $revokedSession->id)
            ->getJson('/api/student/session')
            ->assertStatus(401);
    }

    /** TM-13, TM-17 | FR-8 | duplicate operation & boundary */
    public function test_duplicate_active_display_name_returns_409_and_expired_name_can_rejoin(): void
    {
        $examiner = User::factory()->examiner()->create();
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create([
            'join_code' => 'ROOM2345',
        ]);

        $this->postJson('/api/student/join', [
            'display_name' => 'Alex Rivera',
            'class_code' => 'ROOM2345',
        ])->assertStatus(201);

        // Case-variant and extra-whitespace duplicate rejected with 409
        $this->postJson('/api/student/join', [
            'display_name' => '  alex   rivera  ',
            'class_code' => 'ROOM2345',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'display_name_taken');

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'student.join_failed',
            'target_id' => $classroom->id,
        ]);

        // Expired session frees the display name on next join
        StudentSession::factory()->for($classroom)->expired()->create([
            'display_name' => 'Timed Out',
            'normalized_display_name' => 'timed out',
        ]);

        $this->postJson('/api/student/join', [
            'display_name' => 'Timed Out',
            'class_code' => 'ROOM2345',
        ])->assertStatus(201);
    }

    /** TM-16 | FR-8 | boundary condition */
    public function test_student_join_rate_limiter_returns_429_after_excessive_attempts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/student/join', [
                'display_name' => "Student {$i}",
                'class_code' => 'NONE9999',
            ])->assertStatus(404);
        }

        $this->postJson('/api/student/join', [
            'display_name' => 'Student 11',
            'class_code' => 'NONE9999',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_attempts');

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'auth.lockout',
            'actor_label' => 'Student 11',
        ]);
    }

    /** TM-7 | NFR-4 | forbidden access */
    public function test_student_session_cookie_cannot_access_staff_or_audit_routes(): void
    {
        $examiner = User::factory()->examiner()->create();
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create(['join_code' => 'STUF2345']);

        $join = $this->postJson('/api/student/join', [
            'display_name' => 'Curious Student',
            'class_code' => 'stuf2345',
        ])->assertStatus(201);

        $cookie = $join->getCookie('student_session_id', false);
        $this->assertNotNull($cookie);

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie('student_session_id', $cookie->getValue())
            ->getJson('/api/user')
            ->assertStatus(401);

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie('student_session_id', $cookie->getValue())
            ->getJson('/api/admin/examiners')
            ->assertStatus(401);

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie('student_session_id', $cookie->getValue())
            ->getJson('/api/examiner/classes')
            ->assertStatus(401);

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie('student_session_id', $cookie->getValue())
            ->getJson('/api/audit-logs')
            ->assertStatus(401);
    }

    /** TM-21 | FR-1, FR-8 | data integrity failure */
    public function test_database_constraints_protect_users_and_active_student_display_names(): void
    {
        $examiner = User::factory()->examiner()->create();
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create();

        try {
            DB::transaction(function (): void {
                DB::table('users')->insert([
                    'username' => 'UpperCaseNotAllowed',
                    'password' => 'hash',
                    'role' => 'examiner',
                    'is_active' => true,
                ]);
            });
            $this->fail('Expected check constraint violation for uppercase username.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            DB::transaction(function (): void {
                DB::table('users')->insert([
                    'username' => 'valid_lower',
                    'password' => 'hash',
                    'role' => 'student',
                    'is_active' => true,
                ]);
            });
            $this->fail('Expected check constraint violation for invalid role.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        StudentSession::factory()->for($classroom)->create([
            'display_name' => 'Sam',
            'normalized_display_name' => 'sam',
            'revoked_at' => null,
        ]);

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($classroom): void {
            DB::table('student_sessions')->insert([
                'id' => (string) Str::uuid(),
                'classroom_id' => $classroom->id,
                'display_name' => 'Sam',
                'normalized_display_name' => 'sam',
                'joined_at' => now(),
                'last_seen_at' => now(),
                'expires_at' => now()->addHour(),
                'revoked_at' => null,
            ]);
        });
    }

    /** TM-27 | FR-8, FR-9 | normal behavior */
    public function test_student_kahoot_join_session_and_leave_lifecycle(): void
    {
        $examiner = User::factory()->examiner()->create();
        $classroom = Classroom::factory()->for($examiner, 'examiner')->create([
            'name' => 'SQL Practical 1',
            'join_code' => 'JOIN2345',
            'is_open' => true,
        ]);

        $usersBefore = DB::table('users')->count();

        $join = $this->postJson('/api/student/join', [
            'display_name' => '  Ada Lovelace  ',
            'class_code' => ' join2345 ',
        ]);

        $join->assertStatus(201)
            ->assertJsonPath('data.display_name', 'Ada Lovelace')
            ->assertJsonPath('data.classroom.id', $classroom->id)
            ->assertJsonPath('data.classroom.name', 'SQL Practical 1')
            ->assertJsonPath('data.classroom.join_code', 'JOIN2345');

        $sessionId = $join->json('data.id');
        $this->assertTrue(Str::isUuid((string) $sessionId));

        // Verify zero fake users created for student
        $this->assertSame($usersBefore, DB::table('users')->count());

        // Verify signed, encrypted, HTTP-only, SameSite=Lax cookie
        $cookie = $join->getCookie('student_session_id', false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertNotSame($sessionId, $cookie->getValue());

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'student.joined_class',
            'actor_type' => 'student',
            'actor_id' => $sessionId,
            'actor_label' => 'Ada Lovelace',
            'target_type' => 'classroom',
            'target_id' => $classroom->id,
        ]);

        // Fetch active session
        $this->withCredentials()
            ->withUnencryptedCookie('student_session_id', $cookie->getValue())
            ->getJson('/api/student/session')
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.display_name', 'Ada Lovelace')
            ->assertJsonPath('data.classroom.join_code', 'JOIN2345');

        // Leave class
        $leave = $this->withCredentials()
            ->withUnencryptedCookie('student_session_id', $cookie->getValue())
            ->postJson('/api/student/leave');

        $leave->assertOk()
            ->assertExactJson(['message' => 'Left class.']);

        $expiredCookie = $leave->getCookie('student_session_id', false);
        $this->assertNotNull($expiredCookie);
        $this->assertLessThan(time(), $expiredCookie->getExpiresTime());

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'student.left_class',
            'actor_type' => 'student',
            'actor_id' => $sessionId,
        ]);

        // Reusing cookie after leave fails
        $this->withCredentials()
            ->withUnencryptedCookie('student_session_id', $cookie->getValue())
            ->getJson('/api/student/session')
            ->assertStatus(401);

        // Display name is now free to rejoin
        $this->postJson('/api/student/join', [
            'display_name' => 'Ada Lovelace',
            'class_code' => 'JOIN2345',
        ])->assertStatus(201);
    }
}
