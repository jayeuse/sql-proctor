<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\StudentSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StudentSession>
 */
class StudentSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->name();

        return [
            'classroom_id' => Classroom::factory(),
            'display_name' => $name,
            'normalized_display_name' => Str::lower($name),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'joined_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addHours(4),
            'revoked_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'revoked_at' => now()->subMinute(),
        ]);
    }
}
