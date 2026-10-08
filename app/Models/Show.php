<?php

namespace App\Models;

use Database\Factories\ShowFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;

class Show extends Model
{
    /** @use HasFactory<ShowFactory> */
    use HasFactory, Searchable;

    protected $primaryKey = 'showid';

    public $incrementing = false;

    protected $guarded = [];

    protected $attributes = [
        'artistid' => 1,
    ];

    /**
     * `showdate` is deliberately left uncast. The upstream API represents it as
     * a plain "YYYY-MM-DD" string, the frontend compares it lexicographically,
     * and a date cast would both write a datetime into the column and serialize
     * it back out as ISO-8601 — neither of which matches that contract.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'showyear' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venueid', 'venueid');
    }

    /**
     * @return BelongsTo<Tour, $this>
     */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class, 'tourid', 'tourid');
    }

    /**
     * @return HasMany<SetlistEntry, $this>
     */
    public function setlistEntries(): HasMany
    {
        return $this->hasMany(SetlistEntry::class, 'showid', 'showid');
    }

    /**
     * What the top-bar search matches a show on: its date (ISO and US-style, so
     * "7/4/2023" finds it too), venue, place and billed artist. `date_rank` is
     * carried only for ranking, so newer shows lead among equal matches.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        [$year, $month, $day] = array_map('intval', explode('-', (string) $this->showdate) + [0, 0, 0]);

        return [
            'showid' => (int) $this->showid,
            'showdate' => (string) $this->showdate,
            'us_date' => "{$month}/{$day}/{$year}",
            'artist_name' => $this->artist_name,
            'venuename' => $this->venue?->venuename,
            'city' => $this->venue?->city,
            'state' => $this->venue?->state,
            'country' => $this->venue?->country,
            'date_rank' => (int) str_replace('-', '', (string) $this->showdate),
        ];
    }

    /**
     * Load the venue for every batch Scout indexes, however it was gathered.
     *
     * @param  Collection<int, static>  $models
     * @return Collection<int, static>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models instanceof EloquentCollection ? $models->loadMissing('venue') : $models;
    }
}
