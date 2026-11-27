<?php

namespace Tests\Feature\Api;

use App\Http\Support\ApiResponse;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ResponseEnvelopeTest extends TestCase
{
    public function test_error_helper_returns_non_2xx_json_with_optional_code_and_headers(): void
    {
        Route::middleware('api')->get('/api/_test/error-helper', fn () => ApiResponse::error(
            'This account is locked.',
            423,
            'account_locked',
            ['X-Custom' => '1'],
        ));

        $response = $this->getJson('/api/_test/error-helper');

        $response->assertStatus(423)
            ->assertHeader('X-Custom', '1')
            ->assertExactJson([
                'message' => 'This account is locked.',
                'code' => 'account_locked',
            ]);
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_resource_helper_returns_data_envelope_for_200_and_201(): void
    {
        Route::middleware('api')->get('/api/_test/resource', fn () => ApiResponse::resource(
            new JsonResource(['id' => 1, 'name' => 'Alpha']),
        ));
        Route::middleware('api')->post('/api/_test/resource', fn () => ApiResponse::resource(
            new JsonResource(['id' => 2, 'name' => 'Beta']),
            201,
        ));

        $this->getJson('/api/_test/resource')
            ->assertOk()
            ->assertExactJson(['data' => ['id' => 1, 'name' => 'Alpha']]);

        $this->postJson('/api/_test/resource')
            ->assertCreated()
            ->assertExactJson(['data' => ['id' => 2, 'name' => 'Beta']]);
    }

    public function test_message_and_no_content_helpers_return_documented_shapes(): void
    {
        Route::middleware('api')->patch('/api/_test/message', fn () => ApiResponse::message('Password changed.'));
        Route::middleware('api')->post('/api/_test/no-content', fn () => ApiResponse::noContent());

        $this->patchJson('/api/_test/message')
            ->assertOk()
            ->assertExactJson(['message' => 'Password changed.']);

        $noContent = $this->postJson('/api/_test/no-content');
        $noContent->assertNoContent();
        $this->assertSame('', $noContent->getContent());
    }

    public function test_existing_user_route_returns_resource_data_envelope(): void
    {
        $user = User::factory()->make([
            'id' => 1,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonMissingPath('name');
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }
}
