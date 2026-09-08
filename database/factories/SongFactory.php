<?php

namespace Database\Factories;

use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Song>
 */
class SongFactory extends Factory
{
    protected $model = Song::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $songid = fake()->unique()->numberBetween(1, 2000000000);
        $song = Str::title(fake()->word().' '.fake()->word());

        // Two random words collide often enough to trip the unique slug index,
        // and the id is already unique.
        return [
            'songid' => $songid,
            'song' => $song,
            'slug' => Str::slug($song).'-'.$songid,
            'artist' => 'Phish',
            'times_played' => fake()->numberBetween(1, 500),
            'debut' => fake()->dateTimeBetween('-40 years')->format('Y-m-d'),
            'last_played' => fake()->dateTimeBetween('-2 years')->format('Y-m-d'),
            'gap' => fake()->numberBetween(0, 200),
        ];
    }
}
