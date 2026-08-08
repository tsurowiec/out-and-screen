<?php

namespace Database\Factories;

use App\Models\ScreenTimeLimitOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreenTimeLimitOverride>
 */
class ScreenTimeLimitOverrideFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => today(),
            'minutes' => fake()->numberBetween(0, 360),
        ];
    }
}
