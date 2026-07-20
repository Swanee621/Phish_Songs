<?php

namespace App\Services\PhishNet;

/**
 * Resolves a US timezone for a venue.
 *
 * The phish.net venue payload carries no timezone — only `city`, `state` and
 * `country` — so it is derived from the state here, across the four mainland US
 * zones. Anything unrecognised (a blank state, or the occasional overseas run)
 * falls back to Eastern.
 *
 * Precision matters less than it looks: callers use this to bound a six-hour
 * show window around a three-hour show, so a zone that is an hour off still
 * lands inside the window. Where a state spans two zones the busier venue wins
 * (Tennessee resolves to Central for Nashville and Memphis; Kentucky to Eastern
 * for Louisville).
 */
class VenueTimezone
{
    public const FALLBACK = 'America/New_York';

    /**
     * US states to IANA zone.
     *
     * @var array<string, string>
     */
    protected const STATES = [
        'CT' => 'America/New_York',
        'DC' => 'America/New_York',
        'DE' => 'America/New_York',
        'FL' => 'America/New_York',
        'GA' => 'America/New_York',
        'IN' => 'America/New_York',
        'KY' => 'America/New_York',
        'MA' => 'America/New_York',
        'MD' => 'America/New_York',
        'ME' => 'America/New_York',
        'MI' => 'America/New_York',
        'NC' => 'America/New_York',
        'NH' => 'America/New_York',
        'NJ' => 'America/New_York',
        'NY' => 'America/New_York',
        'OH' => 'America/New_York',
        'PA' => 'America/New_York',
        'RI' => 'America/New_York',
        'SC' => 'America/New_York',
        'VA' => 'America/New_York',
        'VT' => 'America/New_York',
        'WV' => 'America/New_York',

        'AL' => 'America/Chicago',
        'AR' => 'America/Chicago',
        'IA' => 'America/Chicago',
        'IL' => 'America/Chicago',
        'KS' => 'America/Chicago',
        'LA' => 'America/Chicago',
        'MN' => 'America/Chicago',
        'MO' => 'America/Chicago',
        'MS' => 'America/Chicago',
        'NE' => 'America/Chicago',
        'OK' => 'America/Chicago',
        'TN' => 'America/Chicago',
        'TX' => 'America/Chicago',
        'WI' => 'America/Chicago',

        /**
         * Arizona skips DST, so in summer it runs on Pacific rather than
         * Mountain time. Every Phoenix-area show has been indoors in summer.
         */
        'AZ' => 'America/Phoenix',
        'CO' => 'America/Denver',
        'ID' => 'America/Denver',
        'MT' => 'America/Denver',
        'NM' => 'America/Denver',
        'UT' => 'America/Denver',

        'CA' => 'America/Los_Angeles',
        'NV' => 'America/Los_Angeles',
        'OR' => 'America/Los_Angeles',
        'WA' => 'America/Los_Angeles',
    ];

    public function resolve(?string $state): string
    {
        return self::STATES[strtoupper(trim((string) $state))] ?? self::FALLBACK;
    }

    /**
     * Resolve a venue from a phish.net show or setlist payload row.
     *
     * @param  array<string, mixed>  $show
     */
    public function resolveForShow(array $show): string
    {
        return $this->resolve(isset($show['state']) ? (string) $show['state'] : null);
    }
}
