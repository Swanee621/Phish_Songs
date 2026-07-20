<?php

namespace App\Models;

use Database\Factories\ShowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Show extends Model
{
    /** @use HasFactory<ShowFactory> */
    use HasFactory;

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
}
