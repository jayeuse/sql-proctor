<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Support\ApiResponse;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $query = AuditLog::query();

        foreach (['actor_type', 'actor_id', 'event_type', 'target_type', 'target_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $rawTo = (string) $request->validated('to');
            $toDate = $request->date('to');

            if ($toDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($rawTo)) === 1) {
                $toDate = $toDate->copy()->endOfDay();
            }

            $query->where('created_at', '<=', $toDate);
        }

        $logs = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->appends($request->query());

        return ApiResponse::collection(AuditLogResource::collection($logs));
    }
}
