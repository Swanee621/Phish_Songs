<?php

namespace App\Services\GeoIp;

use App\Models\Location;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

/**
 * Turns a request into a stored visit. The address is used for the lookup and
 * then dropped: only the resolved place and the anonymous visitor id persist.
 */
class VisitRecorder
{
    public function __construct(protected GeoIpResolver $resolver) {}

    public function record(string $ip, string $visitorId, string $path, CarbonInterface $visitedAt): Visit
    {
        $place = $this->resolver->resolve($ip);
        $location = $place === null ? null : $this->locationFor($place);

        return Visit::create([
            'visitor_id' => $visitorId,
            'location_id' => $location?->id,
            'country_code' => $place?->countryCode,
            'path' => $path,
            'visited_at' => $visitedAt,
        ]);
    }

    protected function locationFor(GeoLocation $place): Location
    {
        $key = Location::keyFor($place->countryCode, $place->regionCode, $place->city, $place->latitude, $place->longitude);

        try {
            return Location::firstOrCreate(['key' => $key], [
                'geoname_id' => $place->geonameId,
                'city' => $place->city,
                'region' => $place->region,
                'region_code' => $place->regionCode,
                'country_code' => $place->countryCode,
                'country' => $place->country,
                'latitude' => $place->latitude,
                'longitude' => $place->longitude,
                'accuracy_radius' => $place->accuracyRadius,
            ]);
        } catch (QueryException $e) {
            // Two first visits from one city at once: the other request won the insert.
            return Location::where('key', $key)->firstOr(fn () => throw $e);
        }
    }
}
