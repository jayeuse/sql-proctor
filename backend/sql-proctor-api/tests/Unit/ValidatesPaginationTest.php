<?php

namespace Tests\Unit;

use App\Http\Requests\PaginatedRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidatesPaginationTest extends TestCase
{
    /**
     * @return array<string, array{input: array<string, mixed>, errorKey: string}>
     */
    public static function invalidPaginationProvider(): array
    {
        return [
            'page zero' => ['input' => ['page' => 0], 'errorKey' => 'page'],
            'page negative' => ['input' => ['page' => -1], 'errorKey' => 'page'],
            'page non-numeric string' => ['input' => ['page' => 'abc'], 'errorKey' => 'page'],
            'page float string' => ['input' => ['page' => '1.5'], 'errorKey' => 'page'],
            'page empty string' => ['input' => ['page' => ''], 'errorKey' => 'page'],
            'page array' => ['input' => ['page' => ['1']], 'errorKey' => 'page'],
            'per_page zero' => ['input' => ['per_page' => 0], 'errorKey' => 'per_page'],
            'per_page negative' => ['input' => ['per_page' => -5], 'errorKey' => 'per_page'],
            'per_page non-numeric string' => ['input' => ['per_page' => 'abc'], 'errorKey' => 'per_page'],
            'per_page float string' => ['input' => ['per_page' => '1.5'], 'errorKey' => 'per_page'],
            'per_page empty string' => ['input' => ['per_page' => ''], 'errorKey' => 'per_page'],
            'per_page array' => ['input' => ['per_page' => ['10']], 'errorKey' => 'per_page'],
            'per_page over max' => ['input' => ['per_page' => 201], 'errorKey' => 'per_page'],
        ];
    }

    #[DataProvider('invalidPaginationProvider')]
    public function test_invalid_pagination_inputs_fail_validation(array $input, string $errorKey): void
    {
        $request = new PaginatedRequest;
        $validator = Validator::make($input, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey($errorKey, $validator->errors()->toArray());
    }

    public function test_boundary_pagination_values_pass_validation(): void
    {
        $minRequest = PaginatedRequest::create('/api/_test/items', 'GET', [
            'page' => '1',
            'per_page' => '1',
        ]);
        $minRequest->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $minRequest->validateResolved();

        $this->assertSame(1, $minRequest->page());
        $this->assertSame(1, $minRequest->perPage());

        $maxRequest = PaginatedRequest::create('/api/_test/items', 'GET', [
            'page' => '1',
            'per_page' => '200',
        ]);
        $maxRequest->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $maxRequest->validateResolved();

        $this->assertSame(1, $maxRequest->page());
        $this->assertSame(200, $maxRequest->perPage());
    }

    public function test_omitted_pagination_params_resolve_to_defaults_and_respect_custom_default(): void
    {
        $defaultRequest = PaginatedRequest::create('/api/_test/items', 'GET');
        $defaultRequest->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $defaultRequest->validateResolved();

        $this->assertSame(1, $defaultRequest->page());
        $this->assertSame(50, $defaultRequest->perPage());

        $customRequestClass = new class extends PaginatedRequest
        {
            protected function defaultPerPage(): int
            {
                return 25;
            }
        };

        $customRequest = $customRequestClass::create('/api/_test/items', 'GET');
        $customRequest->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $customRequest->validateResolved();

        $this->assertSame(1, $customRequest->page());
        $this->assertSame(25, $customRequest->perPage());
    }
}
