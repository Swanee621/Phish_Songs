<?php

namespace App\Models;

use Database\Factories\TourFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tour extends Model
{
    /** @use HasFactory<TourFactory> */
    use HasFactory;

    protected $primaryKey = 'tourid';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return HasMany<Show, $this>
     */
    public function shows(): HasMany
    {
        return $this->hasMany(Show::class, 'tourid', 'tourid');
    }
}
