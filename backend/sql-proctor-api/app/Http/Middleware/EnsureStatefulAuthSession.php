<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures stateful session, cookie encryption, and CSRF middleware run on auth routes
 * without double-running when Sanctum's EnsureFrontendRequestsAreStateful already ran.
 */
class EnsureStatefulAuthSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('sanctum') === true) {
            return $next($request);
        }

        $middleware = array_values(array_filter(array_unique([
            config('sanctum.middleware.encrypt_cookies', EncryptCookies::class),
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            config('sanctum.middleware.validate_csrf_token', ValidateCsrfToken::class),
            config('sanctum.middleware.authenticate_session', AuthenticateSession::class),
        ])));

        return (new Pipeline(app()))
            ->send($request)
            ->through($middleware)
            ->then(static fn (Request $passThrough) => $next($passThrough));
    }
}
