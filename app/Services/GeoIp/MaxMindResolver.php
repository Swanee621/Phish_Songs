<?php

namespace App\Services\GeoIp;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Throwable;

/**
 * Looks addresses up in a local GeoLite2 City database, so no visitor address
 * ever leaves the server. The file is fetched by `geoip:update`; until it has
 * run every lookup quietly resolves to nothing.
 */
class MaxMindResolver implements GeoIpResolver
{
    protected ?Reader $reader = null;

    protected bool $warnedMissing = false;

    public function __construct(protected string $databasePath) {}

    public function resolve(string $ip): ?GeoLocation
    {
        if (! $this->isPublicAddress($ip)) {
            return null;
        }

        $reader = $this->reader();

        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->city($ip);
        } catch (AddressNotFoundException) {
            return null;
        } catch (InvalidDatabaseException $e) {
            report($e);

            return null;
        }

        $latitude = $record->location->latitude;
        $longitude = $record->location->longitude;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return new GeoLocation(
            latitude: $latitude,
            longitude: $longitude,
            city: $record->city->name,
            region: $record->mostSpecificSubdivision->name,
            regionCode: $record->mostSpecificSubdivision->isoCode,
            countryCode: $record->country->isoCode,
            country: $record->country->name,
            geonameId: $record->city->geonameId,
            accuracyRadius: $record->location->accuracyRadius,
        );
    }

    protected function reader(): ?Reader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        if (! is_file($this->databasePath)) {
            if (! $this->warnedMissing) {
                Log::warning('GeoIP database missing; run `php artisan geoip:update`.', ['path' => $this->databasePath]);
                $this->warnedMissing = true;
            }

            return null;
        }

        try {
            return $this->reader = new Reader($this->databasePath);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Private and reserved ranges (every local-dev request) are not in the
     * data, so skip the lookup rather than let it throw.
     */
    protected function isPublicAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
