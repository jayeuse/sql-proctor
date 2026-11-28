<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\StudentSession;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the signed, encrypted student_session_id cookie, loads the active StudentSession,
 * verifies the classroom remains open, and binds the session onto the request.
 */
class VerifyStudentSession
{
    public const string COOKIE_NAME = 'student_session_id';

    public const string ATTRIBUTE = 'student_session';

    public function handle(Request $request, Closure $next, string $requirement = 'open'): Response
    {
        $sessionId = $request->cookie(self::COOKIE_NAME);

        if (! is_string($sessionId) || ! Str::isUuid($sessionId)) {
            $this->expireCookie();

            throw new AuthenticationException;
        }

        $session = StudentSession::query()
            ->with('classroom')
            ->find($sessionId);

        if (! $session instanceof StudentSession || ! $session->isActive()) {
            $this->expireCookie();

            throw new AuthenticationException;
        }

        if ($requirement !== 'allow-closed' && (! $session->classroom || ! $session->classroom->is_open)) {
            throw ApiException::forbidden('This class is currently closed.', 'class_closed');
        }

        $session->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set(self::ATTRIBUTE, $session);

        return $next($request);
    }

    private function expireCookie(): void
    {
        Cookie::queue(Cookie::forget(
            self::COOKIE_NAME,
            config('session.path', '/'),
            config('session.domain'),
        ));
    }
}
