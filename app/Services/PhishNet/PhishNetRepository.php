<?php

namespace App\Services\PhishNet;

use App\Models\Show;
use App\Models\Song;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Serves phish.net data out of the local database.
 *
 * The database is the source of truth; the cache in front of it holds the
 * already-serialized payloads and is only invalidated when a sync detects that
 * upstream data actually changed. Nothing here talks to the API.
 *
 * Invalidation is done by version, not deletion: every payload key carries the
 * current version of its scope, and a sync that imports changes publishes a new
 * version rather than forgetting keys. Deleting keys under a read-through cache
 * opened a race — a request that queried the database just before an import
 * commits would write its pre-import rows back into the freshly cleared key,
 * pinning a half-finished setlist there until the next upstream change. Under
 * versioned keys that late write lands under the old version, which nothing
 * reads again, and the payload TTL sweeps it out.
 */
class PhishNetRepository
{
    public const CACHE_PREFIX = 'phishnet';

    /**
     * How long a cached payload lives before being rebuilt from the database.
     * Freshness never depends on this — imports bump the version their keys
     * carry — it only bounds how long payloads stranded under old versions
     * linger in the store.
     */
    protected const CACHE_TTL_SECONDS = 86400;

    /**
     * Columns that reconstruct the flat, denormalized row shape the phish.net
     * setlist endpoints return, which the frontend already consumes.
     *
     * @var array<int, string>
     */
    protected const SETLIST_COLUMNS = [
        'setlist_entries.uniqueid',
        'setlist_entries.showid',
        'setlist_entries.songid',
        'setlist_entries.song',
        'setlist_entries.slug',
        'setlist_entries.set',
        'setlist_entries.position',
        'setlist_entries.transition',
        'setlist_entries.trans_mark',
        'setlist_entries.footnote',
        'setlist_entries.isjam',
        'setlist_entries.isreprise',
        'setlist_entries.isjamchart',
        'setlist_entries.jamchart_description',
        'setlist_entries.tracktime',
        'setlist_entries.gap',
        'setlist_entries.is_original',
        'setlist_entries.artistid',
        'shows.showdate',
        'shows.showyear',
        'shows.permalink',
        'shows.setlistnotes',
        'shows.venueid',
        'shows.tourid',
        'venues.venuename as venue',
        'venues.city',
        'venues.state',
        'venues.country',
        'tours.tourname',
        'tours.tourwhen',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function setlistsForYear(int $year): array
    {
        return $this->cached("year.{$year}", "setlists.year.{$year}", fn () => $this->setlistQuery()
            ->where('shows.showyear', $year)
            ->orderBy('shows.showdate')
            ->orderBy('setlist_entries.position')
            ->get()
            ->all());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function setlistForShowdate(string $showdate): array
    {
        return $this->cached('year.'.substr($showdate, 0, 4), "setlists.showdate.{$showdate}", fn () => $this->setlistQuery()
            ->where('shows.showdate', $showdate)
            ->orderBy('setlist_entries.position')
            ->get()
            ->all());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function showYears(): array
    {
        return $this->cached('shows', 'shows.showyear', fn () => Show::query()
            ->select('showyear')
            ->distinct()
            ->orderBy('showyear')
            ->pluck('showyear')
            ->map(fn (int $year) => ['showyear' => (string) $year])
            ->all());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function songs(): array
    {
        return $this->cached('songs', 'songs', fn () => Song::query()
            ->orderBy('song')
            ->get(['songid', 'song', 'slug', 'artist', 'times_played', 'debut', 'last_played', 'gap'])
            ->map(fn (Song $song) => $song->toArray())
            ->all());
    }

    /**
     * The most recent times a song was played, newest first.
     *
     * Deliberately uncached, unlike the payloads above: it has to be able to
     * include a show that landed minutes ago, and there is no import hook that
     * could invalidate a per-slug key the way {@see forgetYear()} invalidates
     * the year and showdate ones. The cost is bounded — `slug` is indexed, and even the
     * most-played song has only a few hundred rows behind it.
     *
     * @param  int|null  $excludeTourId  A tour to leave out, so the caller can
     *                                   ask for history either side of the one
     *                                   it is already listing in full.
     * @param  int  $offset  How many of the newest rows to skip, for a caller
     *                       paging back through the history a screen at a time.
     * @return array<int, array<string, mixed>>
     */
    public function recentPerformances(string $slug, int $limit, ?int $excludeTourId = null, int $offset = 0): array
    {
        return $this->setlistQuery()
            ->where('setlist_entries.slug', $slug)
            ->where('setlist_entries.artistid', 1)
            /*
             * `tourid != x` alone would also drop the shows that belong to no
             * tour at all, since a NULL comparison is never true.
             */
            ->when($excludeTourId !== null, fn (Builder $query) => $query->where(
                fn (Builder $tour) => $tour
                    ->where('shows.tourid', '!=', $excludeTourId)
                    ->orWhereNull('shows.tourid'),
            ))
            ->orderByDesc('shows.showdate')
            ->orderByDesc('setlist_entries.position')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * The live-status snapshot the browser polls: a version hash that moves
     * whenever the current year's setlist data changes, plus the show-window
     * flag the sync loop last observed. Served straight from cache so a poll
     * never touches the database or the API.
     *
     * @return array{version: ?string, inShowWindow: bool, year: ?int, showdate: ?string, highlightShowdate: ?string, highlightUntil: ?string, currentSongs: ?string, updatedAt: ?string}
     */
    public function liveState(): array
    {
        $live = Cache::get($this->key('live'), []);

        return [
            'version' => $live['version'] ?? null,
            'inShowWindow' => (bool) ($live['inShowWindow'] ?? false),
            'year' => $live['year'] ?? null,
            'showdate' => $live['showdate'] ?? null,
            'highlightShowdate' => $live['highlightShowdate'] ?? null,
            'highlightUntil' => $live['highlightUntil'] ?? null,
            'currentSongs' => $live['currentSongs'] ?? null,
            'updatedAt' => $live['updatedAt'] ?? null,
        ];
    }

    /**
     * Record the snapshot the browser polls. Called by the sync loop after each
     * run, so both the version hash and the window flag stay in step with the
     * data actually stored. The showdate names the show whose window is open, so
     * a page can tell whether it is looking at the one currently being played.
     *
     * `highlightShowdate` outlives that flag: it names the show a page should
     * still be treating as the current one, and `highlightUntil` is the instant
     * it stops — the morning-after grace period, resolved in venue time by
     * {@see PhishNetSynchronizer::highlightWindow()}. `currentSongs` is the run
     * on stage right now, for the header to show while a show is playing.
     */
    public function publishLiveState(
        ?string $version,
        bool $inShowWindow,
        int $year,
        ?string $showdate = null,
        ?string $highlightShowdate = null,
        ?string $highlightUntil = null,
        ?string $currentSongs = null,
    ): void {
        Cache::forever($this->key('live'), [
            'version' => $version,
            'inShowWindow' => $inShowWindow,
            'year' => $year,
            'showdate' => $showdate,
            'highlightShowdate' => $highlightShowdate,
            'highlightUntil' => $highlightUntil,
            'currentSongs' => $currentSongs,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * Invalidate every cached payload derived from the given show year — the
     * year payload and each of its showdates — plus the year list, whose
     * contents shift whenever new shows are imported.
     */
    public function forgetYear(int $year): void
    {
        $this->bumpVersion("year.{$year}");
        $this->bumpVersion('shows');
    }

    public function forgetSongs(): void
    {
        $this->bumpVersion('songs');
    }

    protected function setlistQuery(): Builder
    {
        return DB::table('setlist_entries')
            ->join('shows', 'shows.showid', '=', 'setlist_entries.showid')
            ->leftJoin('venues', 'venues.venueid', '=', 'shows.venueid')
            ->leftJoin('tours', 'tours.tourid', '=', 'shows.tourid')
            ->select(self::SETLIST_COLUMNS);
    }

    protected function key(string $key): string
    {
        return self::CACHE_PREFIX.".{$key}";
    }

    /**
     * The current version of a cache scope, stamped into every payload key the
     * scope covers. Readers only ever read this — publishing a new one is the
     * sync's invalidation.
     */
    protected function version(string $scope): string
    {
        return (string) Cache::get($this->key("version.{$scope}"), 'initial');
    }

    /**
     * Publish a new version for a scope, stranding every payload cached under
     * the old one. An overwrite is atomic on any store, so unlike a forget
     * there is no cleared-key moment for a slow reader to write stale rows
     * into.
     */
    protected function bumpVersion(string $scope): void
    {
        Cache::forever($this->key("version.{$scope}"), (string) Str::ulid());
    }

    /**
     * @param  \Closure(): array<int, mixed>  $callback
     * @return array<int, array<string, mixed>>
     */
    protected function cached(string $scope, string $key, \Closure $callback): array
    {
        $versionedKey = $this->key("{$key}.".$this->version($scope));

        return Cache::remember($versionedKey, self::CACHE_TTL_SECONDS, function () use ($callback) {
            return collect($callback())
                ->map(fn ($row) => is_array($row) ? $row : (array) $row)
                ->all();
        });
    }
}
