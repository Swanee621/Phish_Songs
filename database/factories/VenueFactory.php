<?php

namespace Database\Factories;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    protected $model = Venue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venueid' => fake()->unique()->numberBetween(1, 100000),
            'venuename' => fake()->company().' Theatre',
            'city' => fake()->city(),
            'state' => fake()->randomElement(['VT', 'NY', 'CO', 'CA', 'ME']),
            'country' => 'USA',
        ];
    }
}
