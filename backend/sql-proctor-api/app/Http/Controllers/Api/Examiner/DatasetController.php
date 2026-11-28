<?php

namespace App\Http\Controllers\Api\Examiner;

use App\Enums\ActorType;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Exceptions\SandboxUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Examiner\ListDatasetsRequest;
use App\Http\Requests\Examiner\StoreDatasetRequest;
use App\Http\Requests\Examiner\UpdateDatasetRequest;
use App\Http\Resources\SqlDatasetResource;
use App\Http\Support\ApiResponse;
use App\Models\SqlDataset;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SqlExecution\SchemaIntrospector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class DatasetController extends Controller
{
    public function index(ListDatasetsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SqlDataset::class);

        /** @var User $actor */
        $actor = $request->user();
        $scope = (string) $request->validated('scope', 'all');
        $search = trim((string) $request->validated('search', ''));

        $query = SqlDataset::query()
            ->with('owner:id,username')
            ->withCount('exams');

        if ($actor->role === UserRole::Admin) {
            if ($scope === 'mine') {
                $query->where('owner_id', $actor->id);
            } elseif ($scope === 'shared') {
                $query->where('is_shared', true);
            }
        } else {
            if ($scope === 'mine') {
                $query->where('owner_id', $actor->id);
            } elseif ($scope === 'shared') {
                $query->where('is_shared', true);
            } else {
                $query->where(function ($q) use ($actor): void {
                    $q->where('owner_id', $actor->id)
                        ->orWhere('is_shared', true);
                });
            }
        }

        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where('name', 'ilike', "%{$escaped}%");
        }

        $datasets = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->appends($request->query());

        return ApiResponse::collection(SqlDatasetResource::collection($datasets));
    }

    public function store(StoreDatasetRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('create', SqlDataset::class);

        /** @var User $actor */
        $actor = $request->user();
        $isShared = $actor->role === UserRole::Admin
            ? $request->boolean('is_shared', true)
            : false;

        $dataset = DB::transaction(function () use ($request, $actor, $isShared, $auditLogger): SqlDataset {
            $dataset = SqlDataset::create([
                'owner_id' => $actor->id,
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'schema_sql' => $request->validated('schema_sql'),
                'seed_sql' => $request->validated('seed_sql'),
                'is_shared' => $isShared,
            ]);

            $auditLogger->log(
                eventType: AuditEvent::DatasetCreated,
                actorType: $actor->role === UserRole::Admin ? ActorType::Admin : ActorType::Examiner,
                actorId: (string) $actor->id,
                actorLabel: $actor->username,
                targetType: 'sql_dataset',
                targetId: $dataset->id,
                metadata: [
                    'name' => $dataset->name,
                    'is_shared' => $dataset->is_shared,
                ],
                request: $request,
            );

            return $dataset;
        });

        $dataset->load('owner:id,username')->loadCount('exams');

        return ApiResponse::resource(new SqlDatasetResource($dataset), 201);
    }

    public function show(SqlDataset $dataset): JsonResponse
    {
        Gate::authorize('view', $dataset);

        $dataset->load('owner:id,username')->loadCount('exams');

        return ApiResponse::resource(new SqlDatasetResource($dataset));
    }

    public function update(UpdateDatasetRequest $request, SqlDataset $dataset, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('update', $dataset);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        DB::transaction(function () use ($request, $actor, $dataset, $validated, $auditLogger): void {
            $sqlChanged = (array_key_exists('schema_sql', $validated) && $validated['schema_sql'] !== $dataset->schema_sql)
                || (array_key_exists('seed_sql', $validated) && $validated['seed_sql'] !== $dataset->seed_sql);

            $updates = [];
            foreach (['name', 'description', 'schema_sql', 'seed_sql'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            if ($actor->role === UserRole::Admin && array_key_exists('is_shared', $validated)) {
                $updates['is_shared'] = (bool) $validated['is_shared'];
            }

            if ($sqlChanged) {
                $updates['validated_at'] = null;
                $updates['schema_summary'] = null;
            }

            $dataset->update($updates);

            $auditLogger->log(
                eventType: AuditEvent::DatasetUpdated,
                actorType: $actor->role === UserRole::Admin ? ActorType::Admin : ActorType::Examiner,
                actorId: (string) $actor->id,
                actorLabel: $actor->username,
                targetType: 'sql_dataset',
                targetId: $dataset->id,
                metadata: ['sql_changed' => $sqlChanged],
                request: $request,
            );
        });

        $dataset->refresh()->load('owner:id,username')->loadCount('exams');

        return ApiResponse::resource(new SqlDatasetResource($dataset));
    }

    public function destroy(Request $request, SqlDataset $dataset, AuditLogger $auditLogger): Response
    {
        Gate::authorize('delete', $dataset);

        if ($dataset->exams()->exists()) {
            throw ApiException::conflict(
                'Cannot delete a dataset that is referenced by an exam.',
                'dataset_in_use',
            );
        }

        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($request, $actor, $dataset, $auditLogger): void {
            $datasetId = $dataset->id;
            $name = $dataset->name;
            $dataset->delete();

            $auditLogger->log(
                eventType: AuditEvent::DatasetDeleted,
                actorType: $actor->role === UserRole::Admin ? ActorType::Admin : ActorType::Examiner,
                actorId: (string) $actor->id,
                actorLabel: $actor->username,
                targetType: 'sql_dataset',
                targetId: $datasetId,
                metadata: ['name' => $name],
                request: $request,
            );
        });

        return ApiResponse::noContent();
    }

    public function validateSchema(
        Request $request,
        SqlDataset $dataset,
        SchemaIntrospector $introspector,
        AuditLogger $auditLogger,
    ): JsonResponse {
        Gate::authorize('update', $dataset);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $summary = $introspector->introspect($dataset->schema_sql, $dataset->seed_sql);
        } catch (SandboxUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ApiException::unprocessable(
                'Dataset SQL validation failed: '.$e->getMessage(),
                'dataset_validation_failed',
                ['errors' => ['schema_sql' => [$e->getMessage()]]],
            );
        }

        DB::transaction(function () use ($request, $actor, $dataset, $summary, $auditLogger): void {
            $dataset->forceFill([
                'schema_summary' => $summary,
                'validated_at' => now(),
            ])->save();

            $auditLogger->log(
                eventType: AuditEvent::DatasetValidated,
                actorType: $actor->role === UserRole::Admin ? ActorType::Admin : ActorType::Examiner,
                actorId: (string) $actor->id,
                actorLabel: $actor->username,
                targetType: 'sql_dataset',
                targetId: $dataset->id,
                metadata: ['table_count' => count($summary['tables'] ?? [])],
                request: $request,
            );
        });

        $dataset->refresh()->load('owner:id,username')->loadCount('exams');

        return ApiResponse::resource(new SqlDatasetResource($dataset));
    }
}
