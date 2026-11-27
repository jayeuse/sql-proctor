<?php

namespace App\Http\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;

/**
 * Thin helper for SQL-Proctor's standard JSON response envelopes.
 */
class ApiResponse
{
    public static function resource(JsonResource $resource, int $status = 200): JsonResponse
    {
        return $resource->response()->setStatusCode($status);
    }

    public static function collection(AnonymousResourceCollection|ResourceCollection $collection, int $status = 200): JsonResponse
    {
        return $collection->response()->setStatusCode($status);
    }

    public static function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * @param  array<string, string|array<int, string>>  $headers
     */
    public static function error(string $message, int $status, ?string $code = null, array $headers = []): JsonResponse
    {
        $payload = ['message' => $message];
        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status, $headers);
    }
}
