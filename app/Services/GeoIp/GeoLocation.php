<?php

namespace App\Services\GeoIp;

/**
 * The city-level result of resolving a client address.
 */
final readonly class GeoLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $regionCode = null,
        public ?string $countryCode = null,
        public ?string $country = null,
        public ?int $geonameId = null,
        public ?int $accuracyRadius = null,
    ) {}
}
