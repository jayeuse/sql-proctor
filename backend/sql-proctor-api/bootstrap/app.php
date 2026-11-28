<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\VerifyStudentSession;
use App\Http\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->statefulApi();
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => EnsureRole::class,
            'student.session' => VerifyStudentSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $withRequestId = fn (JsonResponse $response): JsonResponse => $response->header(
                AssignRequestId::HEADER,
                AssignRequestId::resolve($request),
            );

            if ($e instanceof ApiException) {
                $status = $e->getStatusCode();

                if ($status >= 500) {
                    return $withRequestId(ApiResponse::error(
                        'Server error.',
                        $status,
                        headers: $e->getHeaders(),
                    ));
                }

                return $withRequestId(ApiResponse::error(
                    $e->getMessage(),
                    $status,
                    $e->errorCode(),
                    $e->getHeaders(),
                ));
            }

            if ($e instanceof ValidationException) {
                return $withRequestId(response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $e->status));
            }

            if ($e instanceof AuthenticationException) {
                return $withRequestId(ApiResponse::error('Unauthenticated.', 401));
            }

            if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
                $authException = $e instanceof AuthorizationException
                    ? $e
                    : ($e->getPrevious() instanceof AuthorizationException ? $e->getPrevious() : null);

                $policyResponse = $authException?->response();
                $policyCode = $policyResponse?->code();

                if (is_string($policyCode) && $policyCode !== '') {
                    return $withRequestId(ApiResponse::error(
                        $policyResponse->message() ?? 'This action is unauthorized.',
                        403,
                        $policyCode,
                    ));
                }

                return $withRequestId(ApiResponse::error('This action is unauthorized.', 403));
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return $withRequestId(ApiResponse::error('Resource not found.', 404));
            }

            if ($e instanceof TokenMismatchException) {
                return $withRequestId(ApiResponse::error('CSRF token mismatch.', 419));
            }

            if ($e instanceof ThrottleRequestsException) {
                $message = $e->getMessage();
                $resolvedMessage = $message !== '' && $message !== 'Too Many Attempts.'
                    ? $message
                    : 'Too many requests. Try again later.';

                return $withRequestId(ApiResponse::error(
                    $resolvedMessage,
                    429,
                    'too_many_attempts',
                    $e->getHeaders(),
                ));
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $headers = $e->getHeaders();

                return $withRequestId(match (true) {
                    $status === 419 => ApiResponse::error('CSRF token mismatch.', 419, headers: $headers),
                    $status === 404 => ApiResponse::error('Resource not found.', 404, headers: $headers),
                    $status === 403 => ApiResponse::error('This action is unauthorized.', 403, headers: $headers),
                    $status === 401 => ApiResponse::error('Unauthenticated.', 401, headers: $headers),
                    $status >= 500 => ApiResponse::error('Server error.', $status, headers: $headers),
                    default => ApiResponse::error(
                        $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Request error.'),
                        $status,
                        headers: $headers,
                    ),
                });
            }

            return $withRequestId(ApiResponse::error('Server error.', 500));
        });
    })->create();
