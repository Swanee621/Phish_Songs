<?php

namespace App\Services\PhishNet;

use App\Models\PhishNetSyncState;
use App\Models\SetlistEntry;
use App\Models\Show;
use App\Models\Song;
use App\Models\Tour;
use App\Models\Venue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The write path: fetches phish.net payloads, imports them into the local
 * database when they differ from what is already stored, and owns all the
 * "is a show happening right now?" logic that paces the sync loop.
 *
 * The upstream API exposes no modified timestamp, so each payload is hashed and
 * compared against the hash recorded by the previous sync. An unchanged hash
 * means no database writes and no cache invalidation.
 *
 * Every import is idempotent: rows are upserted by their upstream primary key,
 * and rows that have disappeared from the payload are removed so upstream
 * setlist corrections propagate instead of leaving orphans behind.
 */
class PhishNetSynchronizer
{
    /**
     * The phish.net setlist `transition` code that marks the final song of a
     * show. Every other entry carries a lower code describing how it runs into
     * the next song; the last song of the night is the only one tagged with a 6,
     * which is the closest thing the API has to an "end of show" flag.
     */
    protected const int FINAL_SONG_TRANSITION = 6;

    /**
     * The lowest phish.net `transition` code that closes a set. From this code
     * up — a setbreak, the gap before an encore, the end of the show — the band
     * is off stage, so there is nothing currently being played.
     */
    protected const int SET_CLOSING_TRANSITION = 4;

    /**
     * How many active intervals old a published "show underway" snapshot may be
     * and still count as evidence that the show is going. While the loop runs,
     * even failing passes restamp the snapshot, so an upstream outage keeps it
     * fresh; a snapshot older than this means the loop itself was down, and a
     * show published as live days ago must not hijack the restart pass.
     */
    protected const int LIVE_STATE_FRESHNESS_INTERVALS = 4;

    /**
     * How far back {@see syncRecentShow} will keep polling a show whose stored
     * setlist never received its final-song marker, so an upstream oddity that
     * simply never marks one cannot keep an hourly poll alive forever.
     */
    protected const int UNFINISHED_SHOW_MAX_AGE_DAYS = 14;

    public function __construct(
        protected PhishNetClient $client,
        protected PhishNetRepository $repository,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Syncing: fetch, diff, import
    |--------------------------------------------------------------------------
    */

    /**
     * One full pass of the sync, in one of two modes:
     *
     * - Idle (no show tonight): check today's per-showdate feed (catches any
     *   show landing today), the most recent show's feed until it is settled
     *   ({@see syncRecentShow}), and the song catalog (play counts and gaps
     *   move with every played show). Only the heavyweight year feed stays on
     *   a daily cadence ({@see yearFeedIsStale}); it doubles as the catch-all
     *   that heals whatever an outage caused the cheaper feeds to miss.
     * - Show night: poll only tonight's per-showdate feed. That single payload
     *   carries the songs and the show notes both, and doubles as the
     *   end-of-show check, so a live pass costs two API calls in total
     *   (schedule lookup + setlist).
     *
     * Every pass ends by republishing the snapshot the browser polls, so an
     * open page picks up both the new version hash and the current window flag
     * on its next poll without ever reaching the API itself.
     */
    public function syncPass(): void
    {
        $showdate = $this->currentLiveShowdate();

        if ($showdate === null) {
            $this->syncToday();
            $this->syncRecentShow();
            $this->syncSongs();

            if ($this->yearFeedIsStale()) {
                $this->syncYear($this->currentShowYear());
            }

            $this->publishLiveState(null, false);

            return;
        }

        $inShowWindow = $this->syncLiveShow($showdate);

        $this->publishLiveState($showdate, $inShowWindow);
    }

    /**
     * Sync a single show year. Returns true when the payload had changed.
     */
    public function syncYear(int $year): bool
    {
        $rows = $this->client->fetchSetlistsForYear($year);

        return $this->whenChanged("setlists.year.{$year}", $rows, function () use ($year, $rows) {
            $this->importSetlistYear($year, $rows);
            $this->repository->forgetYear($year);
        });
    }

    /**
     * Sync today's per-showdate feed — the idle-hours once-over. Returns true
     * when the payload had changed.
     *
     * One small request answers "did anything land today?" without re-pulling
     * the whole year. A show that slipped past the show-window logic — a
     * matinee, a festival day set, an overseas run outside the US-hours gate —
     * still gets its setlist imported within the hour.
     *
     * "Today" is resolved in gate time, because the server clock runs UTC and
     * would otherwise roll over to tomorrow's date mid-evening US time.
     *
     * Most days the feed is empty and nothing is recorded: an empty payload on
     * a day with no show is the steady state, not a change worth tracking.
     */
    public function syncToday(): bool
    {
        $today = now()
            ->setTimezone((string) config('phishnet.show_window.gate_timezone'))
            ->toDateString();

        return $this->syncShowdateFeed($today);
    }

    /**
     * Keep the most recent show's feed synced until the show is settled.
     * Returns true when the payload had changed.
     *
     * A show is settled once it is more than a day old *and* its stored
     * setlist carries the final-song marker. The day-after leg catches the
     * corrections that mostly land within a day of a show — a fixed song, a
     * footnote, a missed encore. The completeness leg keeps polling a show
     * whose closing songs never arrived at all — the state a sync outage
     * mid-show leaves behind — so a restarted loop finishes the setlist within
     * the hour instead of leaving it to the daily year refresh. Settled shows
     * are left to that refresh, and today's own date is skipped because
     * {@see syncToday} already covers it.
     */
    public function syncRecentShow(): bool
    {
        $latestShowdate = (string) (Show::query()->where('artistid', 1)->max('showdate') ?? '');

        if ($latestShowdate === '') {
            return false;
        }

        $gateNow = now()->setTimezone((string) config('phishnet.show_window.gate_timezone'));

        if ($latestShowdate >= $gateNow->toDateString()) {
            return false;
        }

        $isYesterday = $latestShowdate === $gateNow->copy()->subDay()->toDateString();

        if (! $isYesterday && ! $this->showLooksUnfinished($latestShowdate)) {
            return false;
        }

        return $this->syncShowdateFeed($latestShowdate);
    }

    /**
     * Whether the stored setlist for a date is missing its final-song marker —
     * the fingerprint of a sync that died mid-show. Bounded to the recent past
     * ({@see UNFINISHED_SHOW_MAX_AGE_DAYS}); anything older is left to the
     * daily year refresh.
     */
    protected function showLooksUnfinished(string $showdate): bool
    {
        $oldestWorthPolling = now()
            ->setTimezone((string) config('phishnet.show_window.gate_timezone'))
            ->subDays(self::UNFINISHED_SHOW_MAX_AGE_DAYS)
            ->toDateString();

        if ($showdate < $oldestWorthPolling) {
            return false;
        }

        return ! SetlistEntry::query()
            ->whereIn('showid', Show::query()
                ->where('showdate', $showdate)
                ->where('artistid', 1)
                ->pluck('showid'))
            ->where('artistid', 1)
            ->where('transition', self::FINAL_SONG_TRANSITION)
            ->exists();
    }

    /**
     * Fetch one per-showdate feed and import it on change. Returns true when
     * the payload had changed; an empty feed — a date with no show, the steady
     * state — is skipped without recording anything.
     */
    protected function syncShowdateFeed(string $showdate): bool
    {
        $rows = $this->client->fetchSetlistForShowdate($showdate);

        if ($rows === []) {
            return false;
        }

        return $this->whenChanged("setlists.showdate.{$showdate}", $rows, function () use ($showdate, $rows) {
            $this->importSetlistShowdate($rows);
            $this->repository->forgetYear((int) substr($showdate, 0, 4));
        });
    }

    /**
     * Sync a live show night from its per-showdate feed, in one fetch. Returns
     * true while the show is still going — the night's final song has not yet
     * been marked ({@see setlistHasEnded}).
     *
     * This is the feed that matters during a live show: phish.net refreshes it
     * minutes ahead of the bulk year feed, and its rows carry the show's notes
     * alongside the songs, so one payload keeps the setlist and the notes both
     * current. The current year's cached payload is dropped on change so the
     * page rebuilds it from the rows this just wrote. The same payload answers
     * whether the final song has landed, so the ended-check costs no second
     * request.
     */
    public function syncLiveShow(string $showdate): bool
    {
        $rows = $this->client->fetchSetlistForShowdate($showdate);

        $this->whenChanged("setlists.showdate.{$showdate}", $rows, function () use ($showdate, $rows) {
            $this->importSetlistShowdate($rows);
            $this->repository->forgetYear((int) substr($showdate, 0, 4));
        });

        return ! $this->setlistHasEnded($rows);
    }

    /**
     * Sync the song catalog. Returns true when the payload had changed.
     */
    public function syncSongs(): bool
    {
        $rows = $this->client->fetchSongs();

        return $this->whenChanged('songs', $rows, function () use ($rows) {
            $this->importSongs($rows);
            $this->repository->forgetSongs();
        });
    }

    /**
     * Sync the venue catalog. Returns true when the payload had changed.
     */
    public function syncVenues(): bool
    {
        $rows = $this->client->fetchVenues();

        return $this->whenChanged('venues', $rows, function () use ($rows) {
            $this->importVenues($rows);
        });
    }

    /**
     * Whether the current year's setlist feed is due its daily refresh.
     *
     * The year feed is the one heavyweight payload left on a slow cadence: it
     * refreshes once per `phishnet.sync.catalog_interval`, and only exists to
     * catch corrections to shows older than the day-after window the hourly
     * idle checks already cover ({@see syncToday}, {@see syncRecentShow}).
     */
    public function yearFeedIsStale(): bool
    {
        $checkedAt = PhishNetSyncState::query()
            ->where('key', 'setlists.year.'.$this->currentShowYear())
            ->value('checked_at');

        return $checkedAt === null || Carbon::parse($checkedAt)
            ->lte(now()->subSeconds((int) config('phishnet.sync.catalog_interval')));
    }

    /**
     * The show year the sync loop should be watching.
     *
     * Normally the calendar year, but early in a new year — before that year's
     * first show exists upstream — the previous year is still the live one.
     */
    public function currentShowYear(): int
    {
        $year = (int) now()->year;

        if (Show::query()->where('showyear', $year)->exists()) {
            return $year;
        }

        return (int) (Show::query()->max('showyear') ?? $year);
    }

    /*
    |--------------------------------------------------------------------------
    | Show-window detection
    |--------------------------------------------------------------------------
    */

    /**
     * The showdate whose window the clock currently falls inside, or null when
     * no scheduled show is underway.
     *
     * The outer gate short-circuits most of the day without touching the API.
     * Inside it, exactly one date can have a show in its window — a window
     * opens at the show's local evening and runs into the small hours, so in
     * the gate's evening leg that date is today, and after midnight it is
     * yesterday. Only that one date is worth a schedule lookup.
     */
    public function showdateInWindow(): ?string
    {
        if (! $this->withinGate()) {
            return null;
        }

        $gateNow = now()->setTimezone((string) config('phishnet.show_window.gate_timezone'));

        $showdate = $gateNow->hour >= (int) config('phishnet.show_window.gate_start_hour')
            ? $gateNow->toDateString()
            : $gateNow->copy()->subDay()->toDateString();

        foreach ($this->phishShowsScheduledFor($showdate) as $show) {
            $timezone = $this->venueTimezone(isset($show['state']) ? (string) $show['state'] : null);

            if ($this->nowIsInsideWindowFor($showdate, $timezone)) {
                return $showdate;
            }
        }

        return null;
    }

    /**
     * The showdate of the show currently being played, resilient to a gap in
     * the upstream schedule feed.
     *
     * {@see showdateInWindow} re-derives this from the API on every call, so a
     * single empty response — a cache miss on phish.net's end — would otherwise
     * end a show early. When the lookup comes back empty during the gate hours,
     * this falls back to the show last published as live, so only a marked final
     * song ({@see showHasEnded}) or the gate closing ends it, never a momentary
     * blip. The bias is deliberately toward staying live: the cost of a false
     * positive is one extra poll, of a false negative a frozen live page.
     */
    public function currentLiveShowdate(): ?string
    {
        $showdate = $this->showdateInWindow();

        if ($showdate !== null) {
            return $showdate;
        }

        if (! $this->withinGate()) {
            return null;
        }

        $published = $this->repository->liveState();

        if (! $published['inShowWindow'] || $published['showdate'] === null) {
            return null;
        }

        /*
         * Only a recently published snapshot counts as evidence. A running
         * loop restamps it every pass — failing ones included — so an
         * upstream blip keeps it fresh; one this old means the loop itself
         * was down, and a show published as live on some earlier night must
         * not hijack the restart pass that should be catching up instead.
         */
        $staleBefore = now()->subSeconds(
            self::LIVE_STATE_FRESHNESS_INTERVALS * (int) config('phishnet.sync.active_interval'),
        );

        if ($published['updatedAt'] === null || Carbon::parse($published['updatedAt'])->lte($staleBefore)) {
            return null;
        }

        return $published['showdate'];
    }

    /**
     * Whether a show is live right now — inside a scheduled show's window and
     * not yet finished.
     *
     * This is the pacing signal for the sync loop: it goes true an hour before
     * a typical downbeat rather than at the first song, so the loop is already
     * polling quickly by the time setlist entries start landing, and it goes
     * false again the moment the night's final song is marked ({@see
     * showHasEnded}) rather than idling until the window closes hours later.
     */
    public function inShowWindow(): bool
    {
        $showdate = $this->showdateInWindow();

        return $showdate !== null && ! $this->showHasEnded($showdate);
    }

    /**
     * Whether the show on a given date has played its last song. Fetches the
     * date's setlist to find out; callers that already hold the payload should
     * use {@see setlistHasEnded} instead.
     */
    public function showHasEnded(string $showdate): bool
    {
        return $this->setlistHasEnded($this->client->fetchSetlistForShowdate($showdate));
    }

    /**
     * Whether a setlist payload contains the night's final song.
     *
     * The API has no end-of-show flag, but the closing song of every show is
     * tagged with {@see FINAL_SONG_TRANSITION}, so its presence in the setlist
     * means the night is over even though the time window is still open.
     *
     * Only Phish's own rows count. The per-showdate feed also carries side
     * projects and guest appearances playing that night, and a closing song in
     * one of those would otherwise end Phish's show early — dropping the loop
     * from show-night pacing to hourly with the setlist still half-imported.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function setlistHasEnded(array $rows): bool
    {
        foreach ($rows as $entry) {
            if ((int) ($entry['artistid'] ?? 1) !== 1) {
                continue;
            }

            if ((int) ($entry['transition'] ?? 0) === self::FINAL_SONG_TRANSITION) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a show appears to be actively underway — live (in its window and
     * not finished) *and* already carrying setlist entries upstream.
     */
    public function showInProgress(): bool
    {
        $showdate = $this->showdateInWindow();

        if ($showdate === null) {
            return false;
        }

        $rows = $this->client->fetchSetlistForShowdate($showdate);

        return $rows !== [] && ! $this->setlistHasEnded($rows);
    }

    /**
     * Whether the clock is inside the hours where a US show could be running,
     * evaluated in gate time. Keeps the schedule lookup off the wire for the
     * ~14 hours a day when nothing can possibly be happening.
     */
    protected function withinGate(): bool
    {
        $gateNow = now()->setTimezone((string) config('phishnet.show_window.gate_timezone'));

        return $gateNow->hour >= (int) config('phishnet.show_window.gate_start_hour')
            || $gateNow->hour < (int) config('phishnet.show_window.gate_end_hour');
    }

    /**
     * Whether now falls between the show's local start hour and its end hour
     * the following morning.
     */
    protected function nowIsInsideWindowFor(string $showdate, string $timezone): bool
    {
        $start = Carbon::parse($showdate, $timezone)
            ->setTime((int) config('phishnet.show_window.start_hour'), 0);

        $end = Carbon::parse($showdate, $timezone)
            ->addDay()
            ->setTime((int) config('phishnet.show_window.end_hour'), 0);

        return now()->betweenIncluded($start, $end);
    }

    /**
     * Phish's own shows scheduled for a date, discarding the side projects and
     * guest appearances the endpoint also returns.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function phishShowsScheduledFor(string $showdate): array
    {
        return array_values(array_filter(
            $this->client->fetchShowsForDate($showdate),
            fn (array $show): bool => (int) ($show['artistid'] ?? 0) === 1,
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | The live snapshot the browser polls
    |--------------------------------------------------------------------------
    */

    /**
     * Publish the snapshot the browser polls for live updates.
     *
     * The version is the hash the last year sync recorded, so it moves exactly
     * when the current year's setlist data changes and never touches the API
     * itself. The showdate is the show scheduled for now (or null) so a page can
     * tell it is looking at tonight's show right up to the closing song; the
     * separate live flag is what actually drives pacing and the "live" badges,
     * and it drops as soon as that show ends even though its showdate lingers
     * until the window closes.
     */
    public function publishLiveState(?string $showdate, bool $inShowWindow): void
    {
        $year = $this->currentShowYear();

        $version = PhishNetSyncState::query()
            ->where('key', "setlists.year.{$year}")
            ->value('hash');

        /*
         * While a show is scheduled the page is fed from the per-showdate sync,
         * whose hash moves as songs land — minutes before the year hash catches
         * up. Publishing that hash as the version is what lets an open page pick
         * up tonight's setlist on its next poll instead of waiting for the slow
         * year feed.
         */
        if ($showdate !== null) {
            $showdateVersion = PhishNetSyncState::query()
                ->where('key', "setlists.showdate.{$showdate}")
                ->value('hash');

            if ($showdateVersion !== null) {
                $version = $showdateVersion;
            }
        }

        $highlight = $this->highlightWindow();

        $this->repository->publishLiveState(
            $version,
            $inShowWindow,
            $year,
            $showdate,
            $highlight['showdate'],
            $highlight['until'],
            $inShowWindow ? $this->currentSongRun($showdate) : null,
        );
    }

    /**
     * What is on stage right now, as one line: the newest setlist entry, plus
     * any songs before it that ran straight into the next one.
     *
     * phish.net separates songs with only four marks — `", "`, `""`, `" > "`
     * and `" -> "` — and the two carrying a chevron are the ones meaning the
     * band never stopped playing. So a jam three songs deep reads as
     * "Tweezer > Maze -> Possum" rather than just its tail, while a clean stop
     * before the current song ends the run there.
     *
     * Null between sets: once the newest entry carries a set-closing transition
     * nobody is playing, and holding the last run on screen through a half hour
     * setbreak reads as a stuck header rather than as news.
     */
    public function currentSongRun(?string $showdate): ?string
    {
        if ($showdate === null) {
            return null;
        }

        /*
         * Read straight from the database rather than through the cached
         * setlist payload. That cache is read-through and cleared on import, so
         * a reader that missed just before an import can write its now-stale
         * rows back into the cleared key and pin the header to the previous
         * song until the song after it lands. This runs once per sync, not per
         * request, so the query costs nothing worth caching.
         */
        $rows = SetlistEntry::query()
            ->whereIn('showid', Show::query()->where('showdate', $showdate)->pluck('showid'))
            ->where('artistid', 1)
            ->orderBy('position')
            ->get(['song', 'trans_mark', 'transition'])
            ->map(fn (SetlistEntry $entry): array => [
                'song' => (string) $entry->song,
                'trans_mark' => (string) $entry->trans_mark,
                'transition' => (int) $entry->transition,
            ])
            ->all();

        if ($rows === []) {
            return null;
        }

        $last = count($rows) - 1;

        if ((int) $rows[$last]['transition'] >= self::SET_CLOSING_TRANSITION) {
            return null;
        }

        $first = $last;

        while ($first > 0 && str_contains((string) $rows[$first - 1]['trans_mark'], '>')) {
            $first--;
        }

        $run = '';

        for ($index = $first; $index <= $last; $index++) {
            $run .= $rows[$index]['song'];

            if ($index < $last) {
                $run .= $rows[$index]['trans_mark'];
            }
        }

        return $run;
    }

    /**
     * The show the browser should still be treating as the current one, and the
     * instant that stops being true.
     *
     * A show stays "current" well past its final song — until
     * `show_window.highlight_end_hour` the following day, in the venue's own
     * time — so that someone opening the page the next morning still sees last
     * night highlighted rather than a page that quietly reset overnight.
     *
     * The expiry is published as an absolute instant rather than a flag because
     * the snapshot is only rewritten when the sync loop runs, which during the
     * idle hours is once an hour. Handing the browser the deadline lets it
     * expire the highlight on time regardless of when this last ran.
     *
     * @return array{showdate: ?string, until: ?string}
     */
    public function highlightWindow(): array
    {
        $show = Show::query()
            ->with('venue')
            ->where('artistid', 1)
            ->orderByDesc('showdate')
            ->first();

        if ($show === null) {
            return ['showdate' => null, 'until' => null];
        }

        $until = Carbon::parse($show->showdate, $this->venueTimezone($show->venue?->state))
            ->addDay()
            ->setTime((int) config('phishnet.show_window.highlight_end_hour'), 0);

        if (now()->greaterThanOrEqualTo($until)) {
            return ['showdate' => null, 'until' => null];
        }

        return [
            'showdate' => (string) $show->showdate,
            'until' => $until->toIso8601String(),
        ];
    }

    /**
     * The tour of the most recent show on record, which is the tour currently
     * in progress (or the one that most recently wrapped).
     *
     * @return array{tourid: int, tourname: ?string, year: int}|null
     */
    public function currentTour(): ?array
    {
        $show = Show::query()
            ->with('tour')
            ->whereNotNull('tourid')
            ->where('artistid', 1)
            ->orderByDesc('showdate')
            ->first();

        if ($show === null) {
            return null;
        }

        return [
            'tourid' => (int) $show->tourid,
            'tourname' => $show->tour?->tourname,
            'year' => (int) $show->showyear,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Change detection
    |--------------------------------------------------------------------------
    */

    /**
     * Run the import callback only when the payload hash differs from the last
     * recorded sync, then record the new hash either way.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  \Closure(): void  $import
     */
    protected function whenChanged(string $key, array $rows, \Closure $import): bool
    {
        $state = PhishNetSyncState::query()->firstOrNew(['key' => $key]);
        $hash = hash('sha256', (string) json_encode($rows));

        if ($state->exists && $state->hash === $hash) {
            $state->forceFill(['checked_at' => now()])->save();

            return false;
        }

        $import();

        $state->forceFill([
            'hash' => $hash,
            'row_count' => count($rows),
            'checked_at' => now(),
            'changed_at' => now(),
        ])->save();

        Log::info('phish.net data changed and was re-imported.', [
            'key' => $key,
            'rows' => count($rows),
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Importing: raw payloads into the database
    |--------------------------------------------------------------------------
    */

    /**
     * Import every setlist row belonging to a single show year.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function importSetlistYear(int $year, array $rows): void
    {
        DB::transaction(function () use ($year, $rows) {
            $this->upsertVenues($rows);
            $this->upsertTours($rows);
            $this->upsertShows($rows);
            $this->upsertSetlistEntries($rows);

            $showIds = collect($rows)->pluck('showid')->unique()->all();

            /*
             * A show or entry that vanished from the payload was withdrawn or
             * corrected upstream, so drop the local copy.
             */
            Show::query()
                ->where('showyear', $year)
                ->when($showIds !== [], fn ($query) => $query->whereNotIn('showid', $showIds))
                ->delete();

            SetlistEntry::query()
                ->whereIn('showid', $showIds)
                ->whereNotIn('uniqueid', collect($rows)->pluck('uniqueid')->all())
                ->delete();
        });
    }

    /**
     * Import the setlist for a single show date.
     *
     * Used while a show is being played, when phish.net's per-showdate feed
     * carries the night's new songs minutes before the bulk year feed does.
     * Shares the year import's upserts but scopes its cleanup to the shows in the
     * payload, so it never reaches outside the date it was handed.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function importSetlistShowdate(array $rows): void
    {
        DB::transaction(function () use ($rows) {
            $this->upsertVenues($rows);
            $this->upsertTours($rows);
            $this->upsertShows($rows);
            $this->upsertSetlistEntries($rows);

            $showIds = collect($rows)->pluck('showid')->unique()->all();

            /*
             * Drop entries that vanished from the payload — an upstream setlist
             * correction. An empty payload yields no show ids, so the scope is
             * empty and nothing is deleted rather than the show being wiped.
             */
            SetlistEntry::query()
                ->whereIn('showid', $showIds)
                ->whereNotIn('uniqueid', collect($rows)->pluck('uniqueid')->all())
                ->delete();
        });
    }

    /**
     * Import the full song catalog.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function importSongs(array $rows): void
    {
        $songs = collect($rows)
            ->filter(fn (array $row) => isset($row['songid'], $row['slug']))
            ->unique('slug')
            ->map(fn (array $row) => [
                'songid' => (int) $row['songid'],
                'song' => (string) ($row['song'] ?? ''),
                'slug' => (string) $row['slug'],
                'artist' => $row['artist'] ?? null,
                'times_played' => (int) ($row['times_played'] ?? 0),
                'debut' => $row['debut'] ?? null,
                'last_played' => $row['last_played'] ?? null,
                'gap' => isset($row['gap']) ? (int) $row['gap'] : null,
            ])
            ->values();

        if ($songs->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($songs) {
            $songs->chunk(500)->each(fn ($chunk) => Song::upsert(
                $chunk->all(),
                uniqueBy: ['songid'],
                update: ['song', 'slug', 'artist', 'times_played', 'debut', 'last_played', 'gap'],
            ));

            Song::query()->whereNotIn('songid', $songs->pluck('songid')->all())->delete();
        });
    }

    /**
     * Import the venue catalog.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function importVenues(array $rows): void
    {
        $venues = collect($rows)
            ->filter(fn (array $row) => isset($row['venueid']))
            ->unique('venueid')
            ->map(fn (array $row) => [
                'venueid' => (int) $row['venueid'],
                'venuename' => (string) ($row['venuename'] ?? $row['venue'] ?? ''),
                'city' => $row['city'] ?? null,
                'state' => $row['state'] ?? null,
                'country' => $row['country'] ?? null,
            ])
            ->values();

        if ($venues->isEmpty()) {
            return;
        }

        $venues->chunk(500)->each(fn ($chunk) => Venue::upsert(
            $chunk->all(),
            uniqueBy: ['venueid'],
            update: ['venuename', 'city', 'state', 'country'],
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function upsertVenues(array $rows): void
    {
        $venues = collect($rows)
            ->filter(fn (array $row) => ! empty($row['venueid']))
            ->unique('venueid')
            ->map(fn (array $row) => [
                'venueid' => (int) $row['venueid'],
                'venuename' => (string) ($row['venue'] ?? ''),
                'city' => $row['city'] ?? null,
                'state' => $row['state'] ?? null,
                'country' => $row['country'] ?? null,
            ])
            ->values();

        if ($venues->isNotEmpty()) {
            $venues->chunk(500)->each(fn ($chunk) => Venue::upsert(
                $chunk->all(),
                uniqueBy: ['venueid'],
                update: ['venuename', 'city', 'state', 'country'],
            ));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function upsertTours(array $rows): void
    {
        $tours = collect($rows)
            ->filter(fn (array $row) => ! empty($row['tourid']))
            ->unique('tourid')
            ->map(fn (array $row) => [
                'tourid' => (int) $row['tourid'],
                'tourname' => $row['tourname'] ?? null,
                'tourwhen' => $row['tourwhen'] ?? null,
            ])
            ->values();

        if ($tours->isNotEmpty()) {
            $tours->chunk(500)->each(fn ($chunk) => Tour::upsert(
                $chunk->all(),
                uniqueBy: ['tourid'],
                update: ['tourname', 'tourwhen'],
            ));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function upsertShows(array $rows): void
    {
        $shows = collect($rows)
            ->filter(fn (array $row) => ! empty($row['showid']))
            ->unique('showid')
            ->map(fn (array $row) => [
                'showid' => (int) $row['showid'],
                'showdate' => (string) $row['showdate'],
                'showyear' => (int) ($row['showyear'] ?? substr((string) $row['showdate'], 0, 4)),
                'venueid' => isset($row['venueid']) ? (int) $row['venueid'] : null,
                'tourid' => isset($row['tourid']) ? (int) $row['tourid'] : null,
                'artistid' => (int) ($row['artistid'] ?? 1),
                'permalink' => $row['permalink'] ?? null,
                'setlistnotes' => $row['setlistnotes'] ?? null,
            ])
            ->values();

        if ($shows->isNotEmpty()) {
            $shows->chunk(500)->each(fn ($chunk) => Show::upsert(
                $chunk->all(),
                uniqueBy: ['showid'],
                update: ['showdate', 'showyear', 'venueid', 'tourid', 'artistid', 'permalink', 'setlistnotes'],
            ));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function upsertSetlistEntries(array $rows): void
    {
        $entries = collect($rows)
            ->filter(fn (array $row) => ! empty($row['uniqueid']))
            ->unique('uniqueid')
            ->map(fn (array $row) => [
                'uniqueid' => (int) $row['uniqueid'],
                'showid' => (int) $row['showid'],
                'songid' => isset($row['songid']) ? (int) $row['songid'] : null,
                'song' => (string) ($row['song'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'set' => (string) ($row['set'] ?? ''),
                'position' => (int) ($row['position'] ?? 0),
                'transition' => (int) ($row['transition'] ?? 0),
                'trans_mark' => $row['trans_mark'] ?? null,
                'footnote' => $row['footnote'] ?? null,
                'isjam' => (bool) ($row['isjam'] ?? false),
                'isreprise' => (bool) ($row['isreprise'] ?? false),
                'isjamchart' => (bool) ($row['isjamchart'] ?? false),
                'jamchart_description' => $row['jamchart_description'] ?? null,
                'tracktime' => $row['tracktime'] ?? null,
                'gap' => isset($row['gap']) ? (int) $row['gap'] : null,
                'is_original' => (bool) ($row['is_original'] ?? false),
                'artistid' => (int) ($row['artistid'] ?? 1),
            ])
            ->values();

        if ($entries->isNotEmpty()) {
            $entries->chunk(500)->each(fn ($chunk) => SetlistEntry::upsert(
                $chunk->all(),
                uniqueBy: ['uniqueid'],
                update: [
                    'showid', 'songid', 'song', 'slug', 'set', 'position', 'transition',
                    'trans_mark', 'footnote', 'isjam', 'isreprise', 'isjamchart',
                    'jamchart_description', 'tracktime', 'gap', 'is_original', 'artistid',
                ],
            ));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Venue timezones
    |--------------------------------------------------------------------------
    */

    /**
     * The timezone anything unrecognised — a blank state, or the occasional
     * overseas run — falls back to.
     */
    protected const string FALLBACK_TIMEZONE = 'America/New_York';

    /**
     * US states to IANA zone, across the four mainland zones.
     *
     * The phish.net venue payload carries no timezone — only `city`, `state`
     * and `country` — so it is derived from the state. Precision matters less
     * than it looks: callers use this to bound a six-hour show window around a
     * three-hour show, so a zone that is an hour off still lands inside the
     * window. Where a state spans two zones the busier venue wins (Tennessee
     * resolves to Central for Nashville and Memphis; Kentucky to Eastern for
     * Louisville).
     *
     * @var array<string, string>
     */
    protected const array VENUE_TIMEZONES = [
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

    /**
     * Resolve a venue's timezone from its US state.
     */
    protected function venueTimezone(?string $state): string
    {
        return self::VENUE_TIMEZONES[strtoupper(trim((string) $state))] ?? self::FALLBACK_TIMEZONE;
    }
}
