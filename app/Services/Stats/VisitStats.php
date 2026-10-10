<?php

namespace App\Services\Stats;

use App\Models\Location;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read models for the stats page. Everything is aggregated by place; no
 * query here ever returns anything about a single request beyond its city.
 */
class VisitStats
{
    /**
     * How many multi-city visitors the page lists at most.
     */
    protected const TRAVELLER_LIMIT = 200;

    /**
     * @return array{from: string|null, to: string|null, visits: int, visitors: int, unresolved: int}
     */
    public function bounds(): array
    {
        $row = Visit::query()
            ->toBase()
            ->selectRaw('MIN(visited_at) AS first_at, MAX(visited_at) AS last_at, COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS visitors')
            ->first();

        return [
            'from' => $row?->first_at ? Carbon::parse($row->first_at)->toDateString() : null,
            'to' => $row?->last_at ? Carbon::parse($row->last_at)->toDateString() : null,
            'visits' => (int) ($row->visits ?? 0),
            'visitors' => (int) ($row->visitors ?? 0),
            'unresolved' => Visit::query()->whereNull('location_id')->count(),
        ];
    }

    /**
     * Visit totals per place for the heat layer.
     *
     * @return array<int, array{lat: float, lng: float, count: int, visitors: int, city: string|null, region: string|null, country_code: string|null}>
     */
    public function heatmap(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return $this->inRange(Visit::query(), $from, $to)
            ->join('locations', 'locations.id', '=', 'visits.location_id')
            ->groupBy('locations.id', 'locations.latitude', 'locations.longitude', 'locations.city', 'locations.region', 'locations.country_code')
            ->selectRaw('locations.latitude, locations.longitude, locations.city, locations.region, locations.country_code, COUNT(*) AS count, COUNT(DISTINCT visits.visitor_id) AS visitors')
            ->orderByDesc('count')
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'lat' => (float) $row->latitude,
                'lng' => (float) $row->longitude,
                'count' => (int) $row->count,
                'visitors' => (int) $row->visitors,
                'city' => $row->city,
                'region' => $row->region,
                'country_code' => $row->country_code,
            ])
            ->all();
    }

    /**
     * Visitors seen in more than one place within the range, each with the
     * ordered sequence of places they turned up in. Consecutive visits from
     * the same place fold into one stop.
     *
     * @return array<int, array{visitor_id: string, first_seen: string, last_seen: string, stops: array<int, array{lat: float, lng: float, city: string|null, region: string|null, country_code: string|null, first_at: string, last_at: string, visits: int}>}>
     */
    public function travellers(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $visitorIds = $this->inRange(Visit::query(), $from, $to)
            ->whereNotNull('location_id')
            ->groupBy('visitor_id')
            ->havingRaw('COUNT(DISTINCT location_id) > 1')
            ->orderByRaw('MAX(visited_at) DESC')
            ->limit(self::TRAVELLER_LIMIT)
            ->pluck('visitor_id');

        if ($visitorIds->isEmpty()) {
            return [];
        }

        return $this->inRange(Visit::query(), $from, $to)
            ->with('location')
            ->whereIn('visitor_id', $visitorIds)
            ->whereNotNull('location_id')
            ->orderBy('visited_at')
            ->get()
            ->groupBy('visitor_id')
            ->map(fn (Collection $visits, string $visitorId) => [
                'visitor_id' => $visitorId,
                'first_seen' => $visits->first()->visited_at->toIso8601String(),
                'last_seen' => $visits->last()->visited_at->toIso8601String(),
                'stops' => $this->stops($visits),
            ])
            ->sortByDesc('last_seen')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Visit>  $visits
     * @return array<int, array{lat: float, lng: float, city: string|null, region: string|null, country_code: string|null, first_at: string, last_at: string, visits: int}>
     */
    protected function stops(Collection $visits): array
    {
        $stops = [];
        $current = null;

        foreach ($visits as $visit) {
            /** @var Location $location */
            $location = $visit->location;

            if ($current !== null && $current['location_id'] === $location->id) {
                $current['last_at'] = $visit->visited_at->toIso8601String();
                $current['visits']++;

                continue;
            }

            if ($current !== null) {
                $stops[] = $current;
            }

            $current = [
                'location_id' => $location->id,
                'lat' => $location->latitude,
                'lng' => $location->longitude,
                'city' => $location->city,
                'region' => $location->region,
                'country_code' => $location->country_code,
                'first_at' => $visit->visited_at->toIso8601String(),
                'last_at' => $visit->visited_at->toIso8601String(),
                'visits' => 1,
            ];
        }

        if ($current !== null) {
            $stops[] = $current;
        }

        return array_map(function (array $stop) {
            unset($stop['location_id']);

            return $stop;
        }, $stops);
    }

    /**
     * @param  Builder<Visit>  $query
     * @return Builder<Visit>
     */
    protected function inRange(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('visits.visited_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('visits.visited_at', '<=', $to));
    }
}
