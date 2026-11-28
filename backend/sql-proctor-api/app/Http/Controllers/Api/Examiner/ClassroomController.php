<?php

namespace App\Http\Controllers\Api\Examiner;

use App\Enums\ActorType;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Examiner\StoreClassroomRequest;
use App\Http\Requests\Examiner\UpdateClassroomScheduleRequest;
use App\Http\Requests\PaginatedRequest;
use App\Http\Resources\ClassroomResource;
use App\Http\Support\ApiResponse;
use App\Models\Classroom;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\JoinCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClassroomController extends Controller
{
    public function index(PaginatedRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Classroom::class);

        $classrooms = Classroom::query()
            ->where('examiner_id', $request->user()->id)
            ->withCount('activeStudentSessions')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->appends($request->query());

        return ApiResponse::collection(ClassroomResource::collection($classrooms));
    }

    public function store(StoreClassroomRequest $request, JoinCodeGenerator $codeGenerator, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('create', Classroom::class);

        /** @var User $examiner */
        $examiner = $request->user();

        $classroom = DB::transaction(function () use ($request, $examiner, $codeGenerator, $auditLogger): Classroom {
            $classroom = Classroom::create([
                'examiner_id' => $examiner->id,
                'name' => $request->validated('name'),
                'join_code' => $codeGenerator->generateUnique(),
                'is_open' => $request->boolean('is_open', true),
                'code_expires_at' => $request->validated('code_expires_at'),
            ]);

            $auditLogger->log(
                eventType: AuditEvent::ClassCreated,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'classroom',
                targetId: $classroom->id,
                metadata: [
                    'name' => $classroom->name,
                    'join_code' => $classroom->join_code,
                    'is_open' => (bool) $classroom->is_open,
                ],
                request: $request,
            );

            return $classroom;
        });

        $classroom->loadCount('activeStudentSessions');

        return ApiResponse::resource(new ClassroomResource($classroom), 201);
    }

    public function show(Classroom $classroom): JsonResponse
    {
        Gate::authorize('view', $classroom);

        $classroom->loadCount('activeStudentSessions');

        return ApiResponse::resource(new ClassroomResource($classroom));
    }

    public function open(UpdateClassroomScheduleRequest $request, Classroom $classroom, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('update', $classroom);

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $examiner, $classroom, $auditLogger): void {
            $updates = ['is_open' => true];
            if ($request->has('code_expires_at')) {
                $updates['code_expires_at'] = $request->validated('code_expires_at');
            }

            $classroom->update($updates);

            $auditLogger->log(
                eventType: AuditEvent::ClassOpened,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'classroom',
                targetId: $classroom->id,
                metadata: ['join_code' => $classroom->join_code],
                request: $request,
            );
        });

        $classroom->loadCount('activeStudentSessions');

        return ApiResponse::resource(new ClassroomResource($classroom));
    }

    public function close(Request $request, Classroom $classroom, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('update', $classroom);

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $examiner, $classroom, $auditLogger): void {
            $classroom->update(['is_open' => false]);

            $auditLogger->log(
                eventType: AuditEvent::ClassClosed,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'classroom',
                targetId: $classroom->id,
                metadata: ['join_code' => $classroom->join_code],
                request: $request,
            );
        });

        $classroom->loadCount('activeStudentSessions');

        return ApiResponse::resource(new ClassroomResource($classroom));
    }

    public function regenerateCode(
        UpdateClassroomScheduleRequest $request,
        Classroom $classroom,
        JoinCodeGenerator $codeGenerator,
        AuditLogger $auditLogger,
    ): JsonResponse {
        Gate::authorize('update', $classroom);

        /** @var User $examiner */
        $examiner = $request->user();

        DB::transaction(function () use ($request, $examiner, $classroom, $codeGenerator, $auditLogger): void {
            $previousCode = $classroom->join_code;
            $updates = ['join_code' => $codeGenerator->generateUnique()];
            if ($request->has('code_expires_at')) {
                $updates['code_expires_at'] = $request->validated('code_expires_at');
            }

            $classroom->update($updates);

            $auditLogger->log(
                eventType: AuditEvent::ClassCodeRegenerated,
                actorType: ActorType::Examiner,
                actorId: (string) $examiner->id,
                actorLabel: $examiner->username,
                targetType: 'classroom',
                targetId: $classroom->id,
                metadata: [
                    'previous_code' => $previousCode,
                    'join_code' => $classroom->join_code,
                ],
                request: $request,
            );
        });

        $classroom->loadCount('activeStudentSessions');

        return ApiResponse::resource(new ClassroomResource($classroom));
    }
}
