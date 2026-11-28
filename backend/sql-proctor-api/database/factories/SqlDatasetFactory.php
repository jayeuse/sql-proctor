<?php

namespace Database\Factories;

use App\Models\SqlDataset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SqlDataset>
 */
class SqlDatasetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory()->examiner(),
            'name' => fake()->words(3, true).' Dataset',
            'description' => fake()->sentence(),
            'schema_sql' => 'CREATE TABLE items (id INT PRIMARY KEY, name VARCHAR(80) NOT NULL);',
            'seed_sql' => "INSERT INTO items VALUES (1, 'Alpha'), (2, 'Beta');",
            'schema_summary' => null,
            'is_shared' => false,
            'validated_at' => null,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn (): array => ['is_shared' => true]);
    }
}
