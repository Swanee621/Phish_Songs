<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A city-level place visitors have been resolved to. Never holds an address.
 *
 * @property int $id
 * @property string $key
 * @property int|null $geoname_id
 * @property string|null $city
 * @property string|null $region
 * @property string|null $region_code
 * @property string|null $country_code
 * @property string|null $country
 * @property float $latitude
 * @property float $longitude
 * @property int|null $accuracy_radius
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * The dedup key for a resolved place. Coordinates are rounded so a few
     * metres of jitter between database releases does not fork a city into
     * several rows.
     */
    public static function keyFor(
        ?string $countryCode,
        ?string $regionCode,
        ?string $city,
        float $latitude,
        float $longitude,
    ): string {
        return sha1(implode('|', [
            $countryCode ?? '',
            $regionCode ?? '',
            $city ?? '',
            number_format($latitude, 4, '.', ''),
            number_format($longitude, 4, '.', ''),
        ]));
    }
}
