<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and propagates a sanitized X-Request-Id for API requests.
 */
class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    public const string ATTRIBUTE = 'request_id';

    private const string PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public static function resolve(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $incoming = $request->headers->get(self::HEADER);
        $requestId = is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        Log::shareContext(['request_id' => $requestId]);

        return $requestId;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! ($request->is('api/*') || $request->expectsJson())) {
            return $next($request);
        }

        $requestId = self::resolve($request);
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
