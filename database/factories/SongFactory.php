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
        $song = fake()->unique()->word().' '.fake()->word();

        return [
            'songid' => fake()->unique()->numberBetween(1, 100000),
            'song' => Str::title($song),
            'slug' => Str::slug($song),
            'artist' => 'Phish',
            'times_played' => fake()->numberBetween(1, 500),
            'debut' => fake()->date(),
            'last_played' => fake()->date(),
            'gap' => fake()->numberBetween(0, 50),
        ];
    }
}
