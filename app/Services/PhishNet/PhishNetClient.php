<?php

namespace App\Services\PhishNet;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the phish.net v5 API.
 *
 * Every resource fetched here is mirrored into the local database by
 * {@see PhishNetSynchronizer} and read back through {@see PhishNetRepository},
 * so the only callers are sync routines that need a fresh payload to compare
 * against what is already stored. Nothing in a web request path reaches this
 * class, which keeps the API key off every user-facing response.
 */
class PhishNetClient
{
    protected const BASE_URL = 'https://api.phish.net/v5';

    public function __construct(protected string $apiKey) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchSetlistsForYear(int $year): array
    {
        return $this->get("setlists/showyear/{$year}.json");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchSetlistForShowdate(string $showdate): array
    {
        return $this->get("setlists/showdate/{$showdate}.json");
    }

    /**
     * Shows scheduled for a single date.
     *
     * Unlike the setlist feeds, this returns shows that have not been played
     * yet, which is what makes it usable for detecting a show happening today.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchShowsForDate(string $showdate): array
    {
        return $this->get("shows/showdate/{$showdate}.json");
    }

    /**
     * The full catalog of every song Phish has ever played live.
     *
     * The upstream API returns duplicate rows for a handful of songs (same
     * slug, different permalink), so this de-dupes by slug.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchSongs(): array
    {
        return collect($this->get('songs.json'))->unique('slug')->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchVenues(): array
    {
        return $this->get('venues.json');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchShowYears(): array
    {
        return $this->get('shows.json', ['order_by' => 'showyear']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    protected function get(string $path, array $query = []): array
    {
        $response = Http::baseUrl(self::BASE_URL)
            ->retry(3, 200, throw: false)
            ->timeout(30)
            ->get($path, [...$query, 'apikey' => $this->apiKey])
            ->throw();

        return $response->json('data') ?? [];
    }
}
