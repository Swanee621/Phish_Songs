<?php

namespace Database\Factories;

use App\Models\Show;
use App\Models\Tour;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Show>
 */
class ShowFactory extends Factory
{
    protected $model = Show::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $showdate = fake()->dateTimeBetween('-30 years');

        return [
            'showid' => fake()->unique()->numberBetween(1, 2000000000),
            'showdate' => $showdate->format('Y-m-d'),
            'showyear' => (int) $showdate->format('Y'),
            'venueid' => Venue::factory(),
            'tourid' => Tour::factory(),
            'artistid' => 1,
            'permalink' => fake()->url(),
            'setlistnotes' => null,
        ];
    }

    public function forYear(int $year): static
    {
        return $this->state(fn () => [
            'showdate' => "{$year}-".fake()->numberBetween(1, 12).'-'.fake()->numberBetween(1, 28),
            'showyear' => $year,
        ]);
    }
}
