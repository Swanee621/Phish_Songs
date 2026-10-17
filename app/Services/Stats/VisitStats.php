<?php

namespace App\Services\Stats;

use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read models for the stats page. Everything is aggregated by place; no
 * query here ever returns anything about a single request beyond its city,
 * its path and when it happened.
 */
class VisitStats
{
    public const VISITS_PER_PAGE = 50;

    /**
     * @return array{from: string|null, to: string|null, visits: int, unresolved: int}
     */
    public function bounds(): array
    {
        $row = Visit::query()
            ->toBase()
            ->selectRaw('MIN(visited_at) AS first_at, MAX(visited_at) AS last_at, COUNT(*) AS visits')
            ->first();

        return [
            'from' => $row?->first_at ? Carbon::parse($row->first_at)->toDateString() : null,
            'to' => $row?->last_at ? Carbon::parse($row->last_at)->toDateString() : null,
            'visits' => (int) ($row->visits ?? 0),
            'unresolved' => Visit::query()->whereNull('location_id')->count(),
        ];
    }

    /**
     * Visit totals per place for the heat layer.
     *
     * @return array<int, array{lat: float, lng: float, count: int, city: string|null, region: string|null, country_code: string|null}>
     */
    public function heatmap(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return $this->inRange(Visit::query(), $from, $to)
            ->join('locations', 'locations.id', '=', 'visits.location_id')
            ->groupBy('locations.id', 'locations.latitude', 'locations.longitude', 'locations.city', 'locations.region', 'locations.country_code')
            ->selectRaw('locations.latitude, locations.longitude, locations.city, locations.region, locations.country_code, COUNT(*) AS count')
            ->orderByDesc('count')
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'lat' => (float) $row->latitude,
                'lng' => (float) $row->longitude,
                'count' => (int) $row->count,
                'city' => $row->city,
                'region' => $row->region,
                'country_code' => $row->country_code,
            ])
            ->all();
    }

    /**
     * One page of the visits in the range, newest first. Unplaced visits are
     * listed too, with no city.
     *
     * @return array{data: array<int, array{id: int, visited_at: string, path: string, lat: float|null, lng: float|null, city: string|null, region: string|null, country_code: string|null}>, meta: array{page: int, perPage: int, total: int, lastPage: int}}
     */
    public function visits(?CarbonInterface $from, ?CarbonInterface $to, int $page): array
    {
        $paginator = $this->inRange(Visit::query(), $from, $to)
            ->with('location')
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->paginate(self::VISITS_PER_PAGE, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (Visit $visit) => [
                    'id' => $visit->id,
                    'visited_at' => $visit->visited_at->toIso8601String(),
                    'path' => $visit->path,
                    'lat' => $visit->location?->latitude,
                    'lng' => $visit->location?->longitude,
                    'city' => $visit->location?->city,
                    'region' => $visit->location?->region,
                    'country_code' => $visit->location?->country_code ?? $visit->country_code,
                ])
                ->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
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
