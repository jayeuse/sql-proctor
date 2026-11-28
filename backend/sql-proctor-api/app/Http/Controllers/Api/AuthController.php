<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActorType;
use App\Enums\AuditEvent;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Support\ApiResponse;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Precomputed bcrypt hash used for constant-time failure checks on nonexistent usernames.
     */
    private const string DUMMY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEeO0uQxQ9X1xQz7x3nB6bN1c2d3e4f5g6.';

    public function login(LoginRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $username = $request->validated('username');
        $password = $request->validated('password');
        $throttleKey = 'auth:login:'.Str::transliterate($username.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $retryAfter = RateLimiter::availableIn($throttleKey);

            $auditLogger->log(
                eventType: AuditEvent::AuthLockout,
                actorType: ActorType::Anonymous,
                actorId: null,
                actorLabel: $username,
                targetType: 'user',
                targetId: null,
                metadata: [
                    'reason' => 'rate_limit_exceeded',
                    'retry_after' => $retryAfter,
                ],
                request: $request,
            );

            throw ApiException::tooManyRequests(
                'Too many login attempts. Try again later.',
                'too_many_attempts',
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $user = User::query()->where('username', $username)->first();
        $passwordValid = $user instanceof User
            ? Hash::check($password, $user->password)
            : Hash::check($password, self::DUMMY_HASH) && false;

        if (! $user instanceof User || ! $passwordValid || ! $user->is_active) {
            RateLimiter::hit($throttleKey, 60);

            $auditLogger->log(
                eventType: AuditEvent::AuthLoginFailed,
                actorType: ActorType::Anonymous,
                actorId: null,
                actorLabel: $username,
                targetType: 'user',
                targetId: $user instanceof User ? (string) $user->id : null,
                metadata: ['reason' => 'invalid_credentials'],
                request: $request,
            );

            throw new ApiException(401, 'Invalid username or password.', 'invalid_credentials');
        }

        RateLimiter::clear($throttleKey);

        Auth::guard('web')->login($user, false);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $auditLogger->log(
            eventType: AuditEvent::AuthLoginSucceeded,
            actorType: $user->isAdmin() ? ActorType::Admin : ActorType::Examiner,
            actorId: (string) $user->id,
            actorLabel: $user->username,
            targetType: 'user',
            targetId: (string) $user->id,
            request: $request,
        );

        return ApiResponse::resource(new UserResource($user));
    }

    public function logout(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $actorType = $user->isAdmin() ? ActorType::Admin : ActorType::Examiner;

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $auditLogger->log(
            eventType: AuditEvent::AuthLogout,
            actorType: $actorType,
            actorId: (string) $user->id,
            actorLabel: $user->username,
            targetType: 'user',
            targetId: (string) $user->id,
            request: $request,
        );

        return ApiResponse::message('Logged out.');
    }

    public function user(Request $request): JsonResponse
    {
        return ApiResponse::resource(new UserResource($request->user()));
    }
}
