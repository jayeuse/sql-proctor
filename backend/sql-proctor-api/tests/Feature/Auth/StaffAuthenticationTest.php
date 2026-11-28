<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** TM-1 | FR-3 | invalid input */
    public function test_login_requires_username_and_password(): void
    {
        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['username', 'password']]);
    }

    /** TM-2 | FR-3, NFR-2 | security violation */
    public function test_failed_login_returns_identical_generic_error_and_omits_password_from_audit(): void
    {
        User::factory()->examiner()->create([
            'username' => 'active_examiner',
            'password' => Hash::make('CorrectPass123!'),
            'is_active' => true,
        ]);

        User::factory()->examiner()->create([
            'username' => 'inactive_examiner',
            'password' => Hash::make('CorrectPass123!'),
            'is_active' => false,
        ]);

        $unknownResponse = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'nonexistent_user',
                'password' => 'SecretPassword999!',
            ]);

        $wrongPasswordResponse = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'active_examiner',
                'password' => 'WrongPassword999!',
            ]);

        $inactiveResponse = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'inactive_examiner',
                'password' => 'CorrectPass123!',
            ]);

        foreach ([$unknownResponse, $wrongPasswordResponse, $inactiveResponse] as $response) {
            $response->assertStatus(401)
                ->assertExactJson([
                    'message' => 'Invalid username or password.',
                    'code' => 'invalid_credentials',
                ]);
        }

        $failedAudits = DB::table('audit_logs')
            ->where('event_type', 'auth.login_failed')
            ->get();

        $this->assertCount(3, $failedAudits);
        foreach ($failedAudits as $audit) {
            $meta = (string) $audit->metadata;
            $this->assertStringNotContainsString('SecretPassword999!', $meta);
            $this->assertStringNotContainsString('WrongPassword999!', $meta);
            $this->assertStringNotContainsString('CorrectPass123!', $meta);
        }
    }

    /** TM-3 | FR-3, NFR-3 | security violation */
    public function test_sanctum_csrf_cookie_endpoint_issues_xsrf_cookie(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:3000')
            ->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $response->assertCookie('XSRF-TOKEN');
    }

    /** TM-3 | FR-3, NFR-3 | security violation */
    public function test_stateful_login_without_csrf_token_returns_419(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'admin',
                'password' => 'StrongPassword123!',
            ])
            ->assertStatus(419)
            ->assertExactJson(['message' => 'CSRF token mismatch.']);
    }

    /** TM-5 | FR-4, FR-5 | unauthorized access */
    public function test_unauthenticated_requests_to_user_and_logout_return_401(): void
    {
        $this->getJson('/api/user')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/logout')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /** TM-6 | FR-4, FR-5 | unauthorized access */
    public function test_deactivated_user_session_and_logged_out_session_are_rejected(): void
    {
        $examiner = User::factory()->examiner()->create([
            'username' => 'session_examiner',
            'password' => Hash::make('StrongPassword123!'),
            'is_active' => true,
        ]);

        $loginResponse = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'session_examiner',
                'password' => 'StrongPassword123!',
            ]);
        $loginResponse->assertOk();

        $sessionCookie = $loginResponse->getCookie(config('session.cookie'), false);
        $this->assertNotNull($sessionCookie);

        // Active session works (reset in-memory guard to prove cookie + DB session work)
        $this->app['auth']->forgetGuards();
        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie(config('session.cookie'), $sessionCookie->getValue())
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.username', 'session_examiner');

        // Deactivate user in DB (simulates admin revocation/deactivation)
        $examiner->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie(config('session.cookie'), $sessionCookie->getValue())
            ->getJson('/api/user')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->app['auth']->forgetGuards();

        // Also test explicit logout invalidates previous session
        $admin = User::factory()->admin()->create([
            'username' => 'logout_admin',
            'password' => Hash::make('StrongPassword123!'),
            'is_active' => true,
        ]);

        $adminLogin = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'logout_admin',
                'password' => 'StrongPassword123!',
            ]);
        $adminLogin->assertOk();
        $adminSessionCookie = $adminLogin->getCookie(config('session.cookie'), false);
        $this->assertNotNull($adminSessionCookie);

        $this->app['auth']->forgetGuards();
        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie(config('session.cookie'), $adminSessionCookie->getValue())
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.username', 'logout_admin');

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie(config('session.cookie'), $adminSessionCookie->getValue())
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Logged out.']);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'auth.logout',
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->id,
        ]);

        $this->app['auth']->forgetGuards();

        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie(config('session.cookie'), $adminSessionCookie->getValue())
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    /** TM-15 | FR-3 | boundary condition */
    public function test_login_rate_limiter_locks_out_after_five_failed_attempts(): void
    {
        User::factory()->examiner()->create([
            'username' => 'locked_user',
            'password' => Hash::make('StrongPassword123!'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('Origin', 'http://localhost:3000')
                ->postJson('/api/auth/login', [
                    'username' => 'Locked_User',
                    'password' => 'WrongPass123!',
                ])
                ->assertStatus(401);
        }

        $lockout = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'locked_user',
                'password' => 'StrongPassword123!',
            ]);

        $lockout->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_attempts');

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'auth.lockout',
            'actor_label' => 'locked_user',
        ]);

        // Different username from same IP is not locked out
        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/login', [
                'username' => 'other_user',
                'password' => 'WrongPass123!',
            ])
            ->assertStatus(401);
    }

    /** TM-24 | FR-3, FR-5 | normal behavior */
    public function test_staff_login_rotates_session_updates_last_login_at_and_returns_user_resource(): void
    {
        $examiner = User::factory()->examiner()->create([
            'username' => 'examiner_one',
            'name' => 'Examiner One',
            'password' => Hash::make('StrongPassword123!'),
            'last_login_at' => null,
        ]);

        $csrfResponse = $this->withHeader('Origin', 'http://localhost:3000')
            ->get('/sanctum/csrf-cookie');
        $preLoginCookie = $csrfResponse->getCookie(config('session.cookie'), false);
        $this->assertNotNull($preLoginCookie);

        $loginResponse = $this->withCredentials()
            ->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie(config('session.cookie'), $preLoginCookie->getValue())
            ->postJson('/api/auth/login', [
                'username' => '  EXAMINER_ONE  ',
                'password' => 'StrongPassword123!',
            ]);

        $loginResponse->assertOk()
            ->assertJsonPath('data.id', $examiner->id)
            ->assertJsonPath('data.username', 'examiner_one')
            ->assertJsonPath('data.name', 'Examiner One')
            ->assertJsonPath('data.role', 'examiner')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $postLoginCookie = $loginResponse->getCookie(config('session.cookie'), false);
        $this->assertNotNull($postLoginCookie);
        $this->assertNotSame($preLoginCookie->getValue(), $postLoginCookie->getValue());

        $examiner->refresh();
        $this->assertNotNull($examiner->last_login_at);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'auth.login_succeeded',
            'actor_type' => 'examiner',
            'actor_id' => (string) $examiner->id,
            'actor_label' => 'examiner_one',
        ]);
    }

    /** TM-3, TM-24, TM-26 | FR-3, FR-4, FR-7 | end-to-end CSRF & CORS exposed headers */
    public function test_staff_full_csrf_session_and_cors_headers_with_csrf_enabled(): void
    {
        $examiner = User::factory()->examiner()->create([
            'username' => 'csrf_examiner',
            'password' => Hash::make('StrongPassword123!'),
        ]);

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
        $preSessionCookie = $csrfResponse->getCookie(config('session.cookie'), false);

        $login = $this->withCredentials()
            ->withHeaders([
                'Origin' => 'http://localhost:3000',
                'X-XSRF-TOKEN' => $rawXsrfCookie->getValue(),
            ])
            ->withUnencryptedCookie(config('session.cookie'), $preSessionCookie->getValue())
            ->withUnencryptedCookie('XSRF-TOKEN', $rawXsrfCookie->getValue())
            ->postJson('/api/auth/login', [
                'username' => 'csrf_examiner',
                'password' => 'StrongPassword123!',
            ]);
        $login->assertOk();

        $postSessionCookie = $login->getCookie(config('session.cookie'), false);
        $postXsrfCookie = $login->getCookie('XSRF-TOKEN', false) ?? $rawXsrfCookie;

        $this->app['auth']->forgetGuards();

        $createdClass = $this->withCredentials()
            ->withHeaders([
                'Origin' => 'http://localhost:3000',
                'X-XSRF-TOKEN' => $postXsrfCookie->getValue(),
            ])
            ->withUnencryptedCookie(config('session.cookie'), $postSessionCookie->getValue())
            ->withUnencryptedCookie('XSRF-TOKEN', $postXsrfCookie->getValue())
            ->postJson('/api/examiner/classes', ['name' => 'CSRF Verified Class']);

        $createdClass->assertStatus(201);
        $classId = $createdClass->json('data.id');

        $this->app['auth']->forgetGuards();
        $closed = $this->withCredentials()
            ->withHeaders([
                'Origin' => 'http://localhost:3000',
                'X-XSRF-TOKEN' => $postXsrfCookie->getValue(),
                'X-Request-Id' => 'req-close-test',
            ])
            ->withUnencryptedCookie(config('session.cookie'), $postSessionCookie->getValue())
            ->withUnencryptedCookie('XSRF-TOKEN', $postXsrfCookie->getValue())
            ->postJson("/api/examiner/classes/{$classId}/close");

        $closed->assertOk()->assertJsonPath('data.is_open', false);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'class.closed',
            'actor_id' => (string) $examiner->id,
            'target_id' => $classId,
        ]);
        $closedAudit = DB::table('audit_logs')->where('event_type', 'class.closed')->where('target_id', $classId)->first();
        $this->assertNotNull($closedAudit->request_id);

        $this->app['auth']->forgetGuards();
        $this->withCredentials()
            ->withHeaders([
                'Origin' => 'http://localhost:3000',
                'X-XSRF-TOKEN' => $postXsrfCookie->getValue(),
            ])
            ->withUnencryptedCookie(config('session.cookie'), $postSessionCookie->getValue())
            ->withUnencryptedCookie('XSRF-TOKEN', $postXsrfCookie->getValue())
            ->postJson('/api/auth/logout')
            ->assertOk();

        $actualExposed = strtolower((string) $closed->headers->get('Access-Control-Expose-Headers'));
        $this->assertStringContainsString('x-request-id', $actualExposed);
        $this->assertStringContainsString('retry-after', $actualExposed);

        $preflight = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/auth/login');
        $this->assertSame('http://localhost:3000', $preflight->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $preflight->headers->get('Access-Control-Allow-Credentials'));
    }
}
