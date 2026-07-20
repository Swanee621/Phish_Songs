<?php

namespace App\Models;

use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    protected $primaryKey = 'venueid';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return HasMany<Show, $this>
     */
    public function shows(): HasMany
    {
        return $this->hasMany(Show::class, 'venueid', 'venueid');
    }
}
