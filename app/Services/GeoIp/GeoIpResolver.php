<?php

namespace App\Services\GeoIp;

interface GeoIpResolver
{
    /**
     * The approximate place an address connects from, or null when it cannot
     * be placed (private ranges, an address missing from the data, no data).
     */
    public function resolve(string $ip): ?GeoLocation;
}
