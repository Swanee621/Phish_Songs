<?php

namespace Database\Factories;

use App\Models\SetlistEntry;
use App\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SetlistEntry>
 */
class SetlistEntryFactory extends Factory
{
    protected $model = SetlistEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $song = fake()->unique()->word().' '.fake()->word();

        return [
            'uniqueid' => fake()->unique()->numberBetween(1, 2000000),
            'showid' => Show::factory(),
            'songid' => fake()->numberBetween(1, 100000),
            'song' => Str::title($song),
            'slug' => Str::slug($song),
            'set' => '1',
            'position' => fake()->numberBetween(1, 20),
            'transition' => 1,
            'trans_mark' => ', ',
            'artistid' => 1,
        ];
    }
}
