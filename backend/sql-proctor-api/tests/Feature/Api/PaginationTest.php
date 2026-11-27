<?php

namespace Tests\Feature\Api;

use App\Http\Requests\PaginatedRequest;
use App\Http\Support\ApiResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/_test/items', function (PaginatedRequest $request) {
            $page = $request->page();
            $perPage = $request->perPage();

            $all = collect(range(1, 5))->map(fn (int $id) => [
                'id' => $id,
                'label' => "Item {$id}",
            ]);

            $paginator = (new LengthAwarePaginator(
                items: $all->forPage($page, $perPage)->values(),
                total: $all->count(),
                perPage: $perPage,
                currentPage: $page,
                options: ['path' => $request->url()],
            ))->appends($request->query());

            return ApiResponse::collection(JsonResource::collection($paginator));
        });
    }

    /**
     * @return array<string, array{query: string, errorKey: string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'page zero' => ['query' => 'page=0', 'errorKey' => 'page'],
            'page negative' => ['query' => 'page=-1', 'errorKey' => 'page'],
            'page string' => ['query' => 'page=abc', 'errorKey' => 'page'],
            'page float' => ['query' => 'page=1.5', 'errorKey' => 'page'],
            'per_page zero' => ['query' => 'per_page=0', 'errorKey' => 'per_page'],
            'per_page over max' => ['query' => 'per_page=201', 'errorKey' => 'per_page'],
            'per_page float' => ['query' => 'per_page=1.5', 'errorKey' => 'per_page'],
            'per_page array' => ['query' => 'per_page[]=10', 'errorKey' => 'per_page'],
        ];
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_pagination_query_returns_422_with_field_errors(string $query, string $errorKey): void
    {
        $response = $this->getJson("/api/_test/items?{$query}");

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => [$errorKey]]);
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_pagination_boundaries_and_out_of_range_page_behave_as_documented(): void
    {
        $this->getJson('/api/_test/items?per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 5);

        $this->getJson('/api/_test/items?per_page=200')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 200)
            ->assertJsonPath('meta.total', 5);

        $this->getJson('/api/_test/items?per_page=2&page=99')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 99)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_default_paginated_collection_returns_laravel_paginator_envelope(): void
    {
        $response = $this->getJson('/api/_test/items');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'label']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'],
            ])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.4.id', 5);

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }
}
