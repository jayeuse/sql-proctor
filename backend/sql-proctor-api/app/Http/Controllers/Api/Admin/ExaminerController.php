<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActorType;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListExaminersRequest;
use App\Http\Requests\Admin\ResetExaminerPasswordRequest;
use App\Http\Requests\Admin\StoreExaminerRequest;
use App\Http\Requests\Admin\UpdateExaminerRequest;
use App\Http\Resources\UserResource;
use App\Http\Support\ApiResponse;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ExaminerController extends Controller
{
    public function index(ListExaminersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $query = User::query()->where('role', UserRole::Examiner);

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $search = $request->validated('username');
        if (is_string($search) && trim($search) !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(trim($search)));
            $query->whereRaw("LOWER(username) LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
        }

        $examiners = $query
            ->orderBy('username')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->appends($request->query());

        return ApiResponse::collection(UserResource::collection($examiners));
    }

    public function store(StoreExaminerRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('create', User::class);

        /** @var User $admin */
        $admin = $request->user();
        $supplied = $request->validated('password');
        $generated = $supplied === null ? Str::password(16) : null;

        $examiner = DB::transaction(function () use ($request, $admin, $supplied, $generated, $auditLogger): User {
            $examiner = User::create([
                'username' => $request->validated('username'),
                'name' => $request->validated('name'),
                'password' => $supplied ?? $generated,
                'role' => UserRole::Examiner,
                'is_active' => true,
            ]);

            $auditLogger->log(
                eventType: AuditEvent::ExaminerCreated,
                actorType: ActorType::Admin,
                actorId: (string) $admin->id,
                actorLabel: $admin->username,
                targetType: 'user',
                targetId: (string) $examiner->id,
                metadata: [
                    'username' => $examiner->username,
                    'credential_source' => $supplied !== null ? 'admin_supplied' : 'generated',
                ],
                request: $request,
            );

            return $examiner;
        });

        return ApiResponse::resource(new UserResource($examiner, $generated), 201);
    }

    public function update(UpdateExaminerRequest $request, User $examiner, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('manageExaminer', $examiner);

        /** @var User $admin */
        $admin = $request->user();
        $changes = $request->safe()->only(['name', 'is_active']);
        $wasActive = (bool) $examiner->is_active;

        DB::transaction(function () use ($request, $admin, $examiner, $changes, $wasActive, $auditLogger): void {
            $examiner->fill($changes);
            $examiner->save();

            $deactivated = $wasActive && ! $examiner->is_active;

            if ($deactivated) {
                $examiner->invalidateSessions();
            }

            $auditLogger->log(
                eventType: $deactivated ? AuditEvent::ExaminerDeactivated : AuditEvent::ExaminerUpdated,
                actorType: ActorType::Admin,
                actorId: (string) $admin->id,
                actorLabel: $admin->username,
                targetType: 'user',
                targetId: (string) $examiner->id,
                metadata: [
                    'username' => $examiner->username,
                    'changed_fields' => array_keys($changes),
                    'is_active' => (bool) $examiner->is_active,
                ],
                request: $request,
            );
        });

        return ApiResponse::resource(new UserResource($examiner));
    }

    public function resetPassword(ResetExaminerPasswordRequest $request, User $examiner, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('manageExaminer', $examiner);

        /** @var User $admin */
        $admin = $request->user();
        $supplied = $request->validated('password');
        $generated = $supplied === null ? Str::password(16) : null;

        DB::transaction(function () use ($request, $admin, $examiner, $supplied, $generated, $auditLogger): void {
            $examiner->forceFill(['password' => $supplied ?? $generated])->save();
            $examiner->invalidateSessions();

            $auditLogger->log(
                eventType: AuditEvent::ExaminerPasswordReset,
                actorType: ActorType::Admin,
                actorId: (string) $admin->id,
                actorLabel: $admin->username,
                targetType: 'user',
                targetId: (string) $examiner->id,
                metadata: [
                    'username' => $examiner->username,
                    'credential_source' => $supplied !== null ? 'admin_supplied' : 'generated',
                ],
                request: $request,
            );
        });

        return ApiResponse::resource(new UserResource($examiner, $generated));
    }
}
