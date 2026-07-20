<?php

namespace Database\Factories;

use App\Models\Tour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tour>
 */
class TourFactory extends Factory
{
    protected $model = Tour::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->numberBetween(1983, (int) now()->year);

        return [
            'tourid' => fake()->unique()->numberBetween(1, 100000),
            'tourname' => "{$year} Summer Tour",
            'tourwhen' => "{$year} Summer",
        ];
    }
}
