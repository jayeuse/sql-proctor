<?php

namespace App\Http\Controllers\Api\Student;

use App\Enums\ActorType;
use App\Enums\AuditEvent;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyStudentSession;
use App\Http\Requests\Student\JoinClassroomRequest;
use App\Http\Resources\StudentSessionResource;
use App\Http\Support\ApiResponse;
use App\Models\Classroom;
use App\Models\StudentSession;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentSessionController extends Controller
{
    private const int SESSION_TTL_MINUTES = 240;

    public function join(JoinClassroomRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $displayName = $request->validated('display_name');
        $normalizedName = Str::lower($displayName);
        $classCode = $request->validated('class_code');
        $throttleKey = 'student:join:'.$request->ip();
        $burstKey = 'student:join:burst:'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10) || RateLimiter::tooManyAttempts($burstKey, 60)) {
            $retryAfter = max(
                RateLimiter::availableIn($throttleKey),
                RateLimiter::availableIn($burstKey),
            );

            $auditLogger->log(
                eventType: AuditEvent::AuthLockout,
                actorType: ActorType::Anonymous,
                actorId: null,
                actorLabel: $displayName,
                targetType: 'classroom',
                targetId: null,
                metadata: [
                    'scope' => 'student_join',
                    'retry_after' => $retryAfter,
                ],
                request: $request,
            );

            throw ApiException::tooManyRequests(
                'Too many join attempts. Try again later.',
                'too_many_attempts',
                ['Retry-After' => (string) $retryAfter],
            );
        }

        RateLimiter::hit($burstKey, 60);

        $previousSessionId = $request->cookie(VerifyStudentSession::COOKIE_NAME);

        /** @var array{session: ?StudentSession, superseded: ?StudentSession, failure: ?array{kind: string, classroom_id: ?string}} $outcome */
        $outcome = DB::transaction(function () use ($request, $displayName, $normalizedName, $classCode, $previousSessionId): array {
            $classroom = Classroom::query()
                ->where('join_code', $classCode)
                ->lockForUpdate()
                ->first();

            if (! $classroom instanceof Classroom) {
                return ['session' => null, 'superseded' => null, 'failure' => ['kind' => 'invalid_code', 'classroom_id' => null]];
            }

            if (! $classroom->is_open) {
                return ['session' => null, 'superseded' => null, 'failure' => ['kind' => 'class_closed', 'classroom_id' => $classroom->id]];
            }

            if ($classroom->isExpired()) {
                return ['session' => null, 'superseded' => null, 'failure' => ['kind' => 'code_expired', 'classroom_id' => $classroom->id]];
            }

            // Retire expired sessions with the same name so stale entries do not block re-entry.
            StudentSession::query()
                ->where('classroom_id', $classroom->id)
                ->where('normalized_display_name', $normalizedName)
                ->whereNull('revoked_at')
                ->where('expires_at', '<=', now())
                ->update(['revoked_at' => now()]);

            $duplicateExists = StudentSession::query()
                ->where('classroom_id', $classroom->id)
                ->where('normalized_display_name', $normalizedName)
                ->whereNull('revoked_at')
                ->exists();

            if ($duplicateExists) {
                return ['session' => null, 'superseded' => null, 'failure' => ['kind' => 'duplicate_display_name', 'classroom_id' => $classroom->id]];
            }

            $now = now();
            $superseded = null;

            if (is_string($previousSessionId) && Str::isUuid($previousSessionId)) {
                $existingSession = StudentSession::query()
                    ->whereKey($previousSessionId)
                    ->whereNull('revoked_at')
                    ->first();

                if ($existingSession instanceof StudentSession) {
                    $existingSession->forceFill(['revoked_at' => $now])->save();
                    $superseded = $existingSession;
                }
            }

            $session = StudentSession::create([
                'classroom_id' => $classroom->id,
                'display_name' => $displayName,
                'normalized_display_name' => $normalizedName,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent() ? Str::limit((string) $request->userAgent(), 512, '') : null,
                'joined_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $now->copy()->addMinutes(self::SESSION_TTL_MINUTES),
                'revoked_at' => null,
            ]);

            $session->setRelation('classroom', $classroom);

            return ['session' => $session, 'superseded' => $superseded, 'failure' => null];
        });

        if ($outcome['failure'] !== null) {
            RateLimiter::hit($throttleKey, 60);

            $failure = $outcome['failure'];
            $auditLogger->log(
                eventType: AuditEvent::StudentJoinFailed,
                actorType: ActorType::Anonymous,
                actorId: null,
                actorLabel: $displayName,
                targetType: 'classroom',
                targetId: $failure['classroom_id'],
                metadata: [
                    'reason' => $failure['kind'],
                    'class_code' => $classCode,
                ],
                request: $request,
            );

            match ($failure['kind']) {
                'invalid_code' => throw ApiException::notFound('Class code not found.', 'class_not_found'),
                'class_closed' => throw ValidationException::withMessages([
                    'class_code' => ['This class is currently closed.'],
                ]),
                'code_expired' => throw ValidationException::withMessages([
                    'class_code' => ['This class code has expired.'],
                ]),
                'duplicate_display_name' => throw ApiException::conflict(
                    'That display name is already in use in this class.',
                    'display_name_taken',
                ),
            };
        }

        /** @var StudentSession $session */
        $session = $outcome['session'];
        $superseded = $outcome['superseded'];

        if ($superseded instanceof StudentSession) {
            $auditLogger->log(
                eventType: AuditEvent::StudentLeftClass,
                actorType: ActorType::Student,
                actorId: $superseded->id,
                actorLabel: $superseded->display_name,
                targetType: 'classroom',
                targetId: $superseded->classroom_id,
                metadata: ['reason' => 'superseded_by_new_join'],
                request: $request,
            );
        }

        $auditLogger->log(
            eventType: AuditEvent::StudentJoinedClass,
            actorType: ActorType::Student,
            actorId: $session->id,
            actorLabel: $session->display_name,
            targetType: 'classroom',
            targetId: $session->classroom_id,
            metadata: [
                'class_code' => $session->classroom->join_code,
            ],
            request: $request,
        );

        Cookie::queue(cookie(
            name: VerifyStudentSession::COOKIE_NAME,
            value: $session->id,
            minutes: self::SESSION_TTL_MINUTES,
            path: config('session.path', '/'),
            domain: config('session.domain'),
            secure: config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));

        return ApiResponse::resource(new StudentSessionResource($session), 201);
    }

    public function show(Request $request): JsonResponse
    {
        /** @var StudentSession $session */
        $session = $request->attributes->get(VerifyStudentSession::ATTRIBUTE);

        return ApiResponse::resource(new StudentSessionResource($session));
    }

    public function leave(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        /** @var StudentSession $session */
        $session = $request->attributes->get(VerifyStudentSession::ATTRIBUTE);

        $session->forceFill(['revoked_at' => now()])->save();

        $auditLogger->log(
            eventType: AuditEvent::StudentLeftClass,
            actorType: ActorType::Student,
            actorId: $session->id,
            actorLabel: $session->display_name,
            targetType: 'classroom',
            targetId: $session->classroom_id,
            request: $request,
        );

        Cookie::queue(Cookie::forget(
            VerifyStudentSession::COOKIE_NAME,
            config('session.path', '/'),
            config('session.domain'),
        ));

        return ApiResponse::message('Left class.');
    }
}
