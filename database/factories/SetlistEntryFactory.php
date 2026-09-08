<?php

namespace Database\Factories;

use App\Models\SetlistEntry;
use App\Models\Show;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SetlistEntry>
 */
class SetlistEntryFactory extends Factory
{
    protected $model = SetlistEntry::class;

    /**
     * The song name and slug are denormalized onto the entry upstream, so they
     * are copied from the related song rather than generated independently.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $song = Song::factory()->create();

        return [
            'uniqueid' => fake()->unique()->numberBetween(1, 2000000000),
            'showid' => Show::factory(),
            'songid' => $song->songid,
            'song' => $song->song,
            'slug' => $song->slug,
            'set' => '1',
            'position' => fake()->numberBetween(1, 20),
            'transition' => 1,
            'trans_mark' => ', ',
            'gap' => fake()->numberBetween(0, 100),
            'artistid' => 1,
        ];
    }

    /**
     * Attach the entry to a show that already exists, keeping the setlist in the
     * order the positions describe.
     */
    public function forShow(Show $show, int $position): static
    {
        return $this->state(fn () => [
            'showid' => $show->showid,
            'position' => $position,
        ]);
    }
}
