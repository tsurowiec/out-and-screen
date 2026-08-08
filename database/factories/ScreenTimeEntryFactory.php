<?php

namespace Database\Factories;

use App\Enums\ScreenType;
use App\Models\ScreenTimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreenTimeEntry>
 */
class ScreenTimeEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(ScreenType::cases()),
            'minutes' => fake()->randomElement([15, 30, 60]),
            'started_at' => today()->addHours(fake()->numberBetween(7, 20)),
        ];
    }
}
