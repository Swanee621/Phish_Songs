<?php

namespace App\Services\PhishNet;

use App\Models\Show;
use App\Models\Song;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Serves phish.net data out of the local database.
 *
 * The database is the source of truth; the cache in front of it holds the
 * already-serialized payloads forever and is only invalidated when a sync
 * detects that upstream data actually changed. Nothing here talks to the API.
 */
class PhishNetRepository
{
    public const CACHE_PREFIX = 'phishnet';

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
        return $this->cached("setlists.year.{$year}", fn () => $this->setlistQuery()
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
        return $this->cached("setlists.showdate.{$showdate}", fn () => $this->setlistQuery()
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
        return $this->cached('shows.showyear', fn () => Show::query()
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
        return $this->cached('songs', fn () => Song::query()
            ->orderBy('song')
            ->get(['songid', 'song', 'slug', 'artist', 'times_played', 'debut', 'last_played', 'gap'])
            ->map(fn (Song $song) => $song->toArray())
            ->all());
    }

    /**
     * The live-status snapshot the browser polls: a version hash that moves
     * whenever the current year's setlist data changes, plus the show-window
     * flag the sync loop last observed. Served straight from cache so a poll
     * never touches the database or the API.
     *
     * @return array{version: ?string, inShowWindow: bool, year: ?int, showdate: ?string, highlightShowdate: ?string, highlightUntil: ?string, updatedAt: ?string}
     */
    public function liveState(): array
    {
        return Cache::get($this->key('live'), []) + [
            'version' => null,
            'inShowWindow' => false,
            'year' => null,
            'showdate' => null,
            'highlightShowdate' => null,
            'highlightUntil' => null,
            'updatedAt' => null,
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
     * {@see PhishNetSynchronizer::highlightWindow()}.
     */
    public function publishLiveState(
        ?string $version,
        bool $inShowWindow,
        int $year,
        ?string $showdate = null,
        ?string $highlightShowdate = null,
        ?string $highlightUntil = null,
    ): void {
        Cache::forever($this->key('live'), [
            'version' => $version,
            'inShowWindow' => $inShowWindow,
            'year' => $year,
            'showdate' => $showdate,
            'highlightShowdate' => $highlightShowdate,
            'highlightUntil' => $highlightUntil,
            'updatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * Drop every cached payload derived from the given show year, plus the
     * catalogs whose contents shift whenever new shows are imported.
     */
    public function forgetYear(int $year): void
    {
        Cache::forget($this->key("setlists.year.{$year}"));
        Cache::forget($this->key('shows.showyear'));

        Show::query()
            ->where('showyear', $year)
            ->pluck('showdate')
            ->each(fn (string $showdate) => Cache::forget(
                $this->key("setlists.showdate.{$showdate}"),
            ));
    }

    public function forgetSongs(): void
    {
        Cache::forget($this->key('songs'));
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
     * @param  \Closure(): array<int, mixed>  $callback
     * @return array<int, array<string, mixed>>
     */
    protected function cached(string $key, \Closure $callback): array
    {
        return Cache::rememberForever($this->key($key), function () use ($callback) {
            return collect($callback())
                ->map(fn ($row) => is_array($row) ? $row : (array) $row)
                ->all();
        });
    }
}
