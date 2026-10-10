<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visitor_id' => fake()->uuid(),
            'location_id' => Location::factory(),
            'country_code' => 'US',
            'path' => '/',
            'visited_at' => now(),
        ];
    }

    /**
     * A visit whose address could not be resolved to a city.
     */
    public function unresolved(): static
    {
        return $this->state(fn () => ['location_id' => null, 'country_code' => null]);
    }
}
