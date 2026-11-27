<?php

namespace Tests\Feature\Api;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ExceptionRenderingTest extends TestCase
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidRequestIdProvider(): array
    {
        return [
            'spaces' => ['bad request id'],
            'slash' => ['bad/request-id'],
            'semicolon' => ['bad;request-id'],
            'over 64 chars' => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('invalidRequestIdProvider')]
    public function test_invalid_inbound_request_id_is_replaced_with_generated_uuid(string $invalidId): void
    {
        Route::middleware('api')->get('/api/_test/ping', fn () => response()->json(['message' => 'ok']));

        $response = $this->withHeaders(['X-Request-Id' => $invalidId])
            ->getJson('/api/_test/ping');

        $response->assertOk();
        $resolvedId = $response->headers->get('X-Request-Id');
        $this->assertNotNull($resolvedId);
        $this->assertNotSame($invalidId, $resolvedId);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $resolvedId);
    }

    public function test_unhandled_exception_returns_fixed_500_body_even_when_app_debug_is_true(): void
    {
        config(['app.debug' => true]);

        Route::middleware('api')->get('/api/_test/boom', function (): void {
            throw new RuntimeException('SQLSTATE[42P01]: Undefined table: secret_table');
        });

        $response = $this->getJson('/api/_test/boom');

        $response->assertStatus(500)
            ->assertExactJson(['message' => 'Server error.']);

        $raw = $response->getContent();
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('SQLSTATE', $raw);
        $this->assertStringNotContainsString('secret_table', $raw);
        $this->assertStringNotContainsString('RuntimeException', $raw);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, (string) $response->headers->get('X-Request-Id'));
    }

    public function test_model_not_found_returns_generic_404_without_leaking_model_class(): void
    {
        Route::middleware('api')->get('/api/_test/missing-model', function (): void {
            throw (new ModelNotFoundException)->setModel(User::class, [42]);
        });

        $response = $this->getJson('/api/_test/missing-model');

        $response->assertStatus(404)
            ->assertExactJson(['message' => 'Resource not found.']);

        $raw = (string) $response->getContent();
        $this->assertStringNotContainsString('User', $raw);
        $this->assertStringNotContainsString('42', $raw);
    }

    public function test_unauthenticated_api_request_without_accept_header_returns_401_json(): void
    {
        Route::middleware('api')->get('/api/_test/unauth', function (): void {
            throw new AuthenticationException;
        });

        $response = $this->get('/api/_test/unauth');

        $response->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, (string) $response->headers->get('X-Request-Id'));
    }

    public function test_authorization_exception_and_coded_forbidden_return_403_json(): void
    {
        Route::middleware('api')->get('/api/_test/forbidden', function (): void {
            throw new AuthorizationException;
        });
        Route::middleware('api')->get('/api/_test/forbidden-coded', function (): void {
            throw ApiException::forbidden('A password change is required before this action.', 'password_change_required');
        });

        $this->getJson('/api/_test/forbidden')
            ->assertStatus(403)
            ->assertExactJson(['message' => 'This action is unauthorized.']);

        $this->getJson('/api/_test/forbidden-coded')
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'A password change is required before this action.',
                'code' => 'password_change_required',
            ]);
    }

    public function test_conflict_and_locked_domain_exceptions_return_exact_status_and_code(): void
    {
        Route::middleware('api')->get('/api/_test/conflict', function (): void {
            throw ApiException::conflict('The last active admin cannot be demoted or suspended.', 'last_active_admin');
        });
        Route::middleware('api')->get('/api/_test/locked', function (): void {
            throw ApiException::locked('This account is locked. Try again later.', 'account_locked');
        });

        $this->getJson('/api/_test/conflict')
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'The last active admin cannot be demoted or suspended.',
                'code' => 'last_active_admin',
            ]);

        $this->getJson('/api/_test/locked')
            ->assertStatus(423)
            ->assertExactJson([
                'message' => 'This account is locked. Try again later.',
                'code' => 'account_locked',
            ]);
    }

    public function test_unknown_api_route_returns_generic_404_with_request_id(): void
    {
        $response = $this->getJson('/api/_test/does-not-exist-anywhere');

        $response->assertStatus(404)
            ->assertExactJson(['message' => 'Resource not found.']);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, (string) $response->headers->get('X-Request-Id'));
    }

    public function test_csrf_token_mismatch_returns_419_json(): void
    {
        Route::middleware('api')->post('/api/_test/csrf', function (): void {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        $this->postJson('/api/_test/csrf')
            ->assertStatus(419)
            ->assertExactJson(['message' => 'CSRF token mismatch.']);
    }

    public function test_throttle_exception_returns_429_with_code_and_retry_after(): void
    {
        Route::middleware('api')->post('/api/_test/throttle', function (): void {
            throw new ThrottleRequestsException('Too many login attempts. Try again later.', null, ['Retry-After' => 42]);
        });

        $response = $this->postJson('/api/_test/throttle');

        $response->assertStatus(429)
            ->assertHeader('Retry-After', '42')
            ->assertExactJson([
                'message' => 'Too many login attempts. Try again later.',
                'code' => 'too_many_attempts',
            ]);
    }

    public function test_valid_request_id_is_echoed_and_validation_exception_returns_422_with_request_id(): void
    {
        Route::middleware('api')->post('/api/_test/validate', function (): void {
            throw ValidationException::withMessages([
                'username' => ['The username field is required.'],
            ]);
        });

        $response = $this->withHeaders(['X-Request-Id' => 'req_abc-123.45'])
            ->postJson('/api/_test/validate');

        $response->assertStatus(422)
            ->assertHeader('X-Request-Id', 'req_abc-123.45')
            ->assertJsonStructure(['message', 'errors' => ['username']]);
    }

    public function test_5xx_api_exception_is_sanitized_to_fixed_server_error_body(): void
    {
        Route::middleware('api')->get('/api/_test/api-5xx', function (): void {
            throw new ApiException(503, 'SQLSTATE[08006]: internal secret', 'internal_db_err');
        });

        $response = $this->getJson('/api/_test/api-5xx');

        $response->assertStatus(503)
            ->assertExactJson(['message' => 'Server error.']);

        $raw = (string) $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $raw);
        $this->assertStringNotContainsString('internal secret', $raw);
        $this->assertStringNotContainsString('internal_db_err', $raw);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, (string) $response->headers->get('X-Request-Id'));
    }
}
