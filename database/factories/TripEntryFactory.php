<?php

namespace Database\Factories;

use App\Models\TripEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripEntry>
 */
class TripEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => today(),
            'minutes' => fake()->randomElement([60, 120, 180, 240]),
            'description' => fake()->optional()->sentence(3),
        ];
    }
}
