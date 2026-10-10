<?php

namespace App\Models;

use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One page view by an anonymous visitor. The visitor id is a random cookie
 * value, and the client address is discarded once it has been resolved to a
 * {@see Location}.
 *
 * @property int $id
 * @property string $visitor_id
 * @property int|null $location_id
 * @property string|null $country_code
 * @property string $path
 * @property Carbon $visited_at
 * @property-read Location|null $location
 */
class Visit extends Model
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
