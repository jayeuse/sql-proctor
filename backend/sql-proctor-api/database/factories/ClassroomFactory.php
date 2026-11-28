<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\User;
use App\Services\JoinCodeGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'examiner_id' => User::factory()->examiner(),
            'name' => fake()->words(3, true),
            'join_code' => JoinCodeGenerator::randomCode(),
            'is_open' => true,
            'code_expires_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'is_open' => false,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'code_expires_at' => now()->subMinute(),
        ]);
    }
}
