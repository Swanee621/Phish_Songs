<?php

namespace App\Services\PhishNet;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the phish.net v5 API.
 *
 * Show, setlist, song and venue data is fetched uncached — those resources are
 * mirrored into the local database by {@see PhishNetSynchronizer} and read back
 * through {@see PhishNetRepository}, so the only callers are sync routines that
 * need a fresh payload to compare against what is already stored.
 *
 * Jam charts are annotations rather than show data, so they are not mirrored and
 * stay cached here.
 */
class PhishNetClient
{
    protected const BASE_URL = 'https://api.phish.net/v5';

    /**
     * TTL for jam chart data, which only grows when a new show is annotated.
     */
    protected const TTL_REFERENCE = 60 * 60 * 24 * 7;

    public function __construct(protected string $apiKey) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function jamCharts(): array
    {
        return $this->cached('jamcharts', self::TTL_REFERENCE, fn () => $this->get('jamcharts.json'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function jamChartForSlug(string $slug): array
    {
        return $this->cached("jamcharts.slug.{$slug}", self::TTL_REFERENCE, fn () => $this->get("jamcharts/slug/{$slug}.json"));
    }

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

    /**
     * @param  \Closure(): array<int, array<string, mixed>>  $callback
     * @return array<int, array<string, mixed>>
     */
    protected function cached(string $key, int $seconds, \Closure $callback): array
    {
        return Cache::remember("phishnet.{$key}", $seconds, $callback);
    }
}
