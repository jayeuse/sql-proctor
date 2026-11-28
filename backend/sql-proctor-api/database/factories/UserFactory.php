<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => Str::lower(fake()->unique()->bothify('user_????####')),
            'name' => fake()->name(),
            'email' => null,
            'email_verified_at' => null,
            'password' => static::$password ??= Hash::make('StrongPassword123!'),
            'role' => UserRole::Examiner,
            'is_active' => true,
            'last_login_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Admin,
        ]);
    }

    public function examiner(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Examiner,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
