<?php

namespace App\Services\PhishNet;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PhishNetClient
{
    protected const BASE_URL = 'https://api.phish.net/v5';

    /**
     * TTL for reference data that only grows when a new show happens (venues, jam chart catalog, etc).
     */
    protected const TTL_REFERENCE = 60 * 60 * 24 * 7;

    /**
     * TTL for data tied to a specific past year/date, which never changes once the year has ended.
     */
    protected const TTL_HISTORICAL = 60 * 60 * 24 * 30;

    /**
     * TTL for data tied to the current year, which can still change mid-tour.
     */
    protected const TTL_LIVE = 60 * 15;

    public function __construct(protected string $apiKey)
    {
    }

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
    public function setlistsForYear(int $year): array
    {
        return $this->cached(
            "setlists.showyear.{$year}",
            $this->ttlForYear($year),
            fn () => $this->get("setlists/showyear/{$year}.json"),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function setlistForShowdate(string $showdate): array
    {
        $year = (int) substr($showdate, 0, 4);

        return $this->cached(
            "setlists.showdate.{$showdate}",
            $this->ttlForYear($year),
            fn () => $this->get("setlists/showdate/{$showdate}.json"),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function showYears(): array
    {
        return $this->cached('shows.showyear', self::TTL_REFERENCE, fn () => $this->get('shows.json', ['order_by' => 'showyear']));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function venues(): array
    {
        return $this->cached('venues', self::TTL_REFERENCE, fn () => $this->get('venues.json'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function showsForVenue(int $venueId): array
    {
        return $this->cached(
            "shows.venueid.{$venueId}",
            self::TTL_REFERENCE,
            fn () => $this->get("shows/venueid/{$venueId}.json", ['order_by' => 'showdate', 'direction' => 'desc']),
        );
    }

    /**
     * The current year's data can still change mid-tour, so cache it briefly.
     * Past years are immutable, so cache them for a long time.
     */
    protected function ttlForYear(int $year): int
    {
        return $year >= now()->year ? self::TTL_LIVE : self::TTL_HISTORICAL;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    protected function get(string $path, array $query = []): array
    {
        $response = Http::baseUrl(self::BASE_URL)
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
