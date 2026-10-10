<?php

namespace App\Services\GeoIp;

/**
 * Test double: answers from a fixed address map so feature tests never need
 * the real database file.
 */
class FakeGeoIpResolver implements GeoIpResolver
{
    /**
     * @param  array<string, GeoLocation|null>  $byAddress
     */
    public function __construct(
        protected array $byAddress = [],
        protected ?GeoLocation $default = null,
    ) {}

    public function resolve(string $ip): ?GeoLocation
    {
        return array_key_exists($ip, $this->byAddress) ? $this->byAddress[$ip] : $this->default;
    }
}
