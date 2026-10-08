<?php

namespace App\Models;

use Database\Factories\SongFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Song extends Model
{
    /** @use HasFactory<SongFactory> */
    use HasFactory, Searchable;

    protected $primaryKey = 'songid';

    public $incrementing = false;

    protected $guarded = [];

    protected $attributes = [
        'times_played' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'times_played' => 'integer',
            'gap' => 'integer',
        ];
    }

    /**
     * What the top-bar search matches a song on. `times_played` is carried only
     * for ranking, so the better-known of two equal matches comes first.
     *
     * @return array{songid: int, song: string, artist: ?string, times_played: int}
     */
    public function toSearchableArray(): array
    {
        return [
            'songid' => (int) $this->songid,
            'song' => (string) $this->song,
            'artist' => $this->artist,
            'times_played' => (int) $this->times_played,
        ];
    }
}
