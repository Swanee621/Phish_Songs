<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $latitude = fake()->latitude();
        $longitude = fake()->longitude();
        $city = fake()->city();
        $regionCode = fake()->randomElement(['VT', 'NY', 'CO', 'CA', 'ME']);

        return [
            'key' => Location::keyFor('US', $regionCode, $city, $latitude, $longitude),
            'geoname_id' => fake()->unique()->numberBetween(1000, 9_999_999),
            'city' => $city,
            'region' => $regionCode,
            'region_code' => $regionCode,
            'country_code' => 'US',
            'country' => 'United States',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_radius' => fake()->randomElement([5, 20, 50, 100]),
        ];
    }
}
