<?php

namespace App\Services\PhishNet;

use App\Models\PhishNetSyncState;
use App\Models\Show;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fetches phish.net payloads, and imports them only when they differ from what
 * is already stored locally.
 *
 * The upstream API exposes no modified timestamp, so each payload is hashed and
 * compared against the hash recorded by the previous sync. An unchanged hash
 * means no database writes and no cache invalidation.
 */
class PhishNetSynchronizer
{
    public function __construct(
        protected PhishNetClient $client,
        protected PhishNetImporter $importer,
        protected PhishNetRepository $repository,
        protected VenueTimezone $timezone,
    ) {}

    /**
     * Sync a single show year. Returns true when the payload had changed.
     */
    public function syncYear(int $year): bool
    {
        $rows = $this->client->fetchSetlistsForYear($year);

        return $this->whenChanged("setlists.year.{$year}", $rows, function () use ($year, $rows) {
            $this->importer->importSetlistYear($year, $rows);
            $this->repository->forgetYear($year);
        });
    }

    /**
     * Sync the song catalog. Returns true when the payload had changed.
     */
    public function syncSongs(): bool
    {
        $rows = $this->client->fetchSongs();

        return $this->whenChanged('songs', $rows, function () use ($rows) {
            $this->importer->importSongs($rows);
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
            $this->importer->importVenues($rows);
        });
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

    /**
     * The showdate whose window the clock currently falls inside, or null when
     * no scheduled show is underway.
     *
     * The outer gate short-circuits most of the day without touching the API.
     * Inside it, today and yesterday are both candidates: an evening show
     * belongs to today's showdate, but after midnight a show that is still
     * running belongs to yesterday's.
     */
    public function showdateInWindow(): ?string
    {
        if (! $this->withinGate()) {
            return null;
        }

        $gateNow = now()->setTimezone((string) config('phishnet.show_window.gate_timezone'));

        foreach ([$gateNow->toDateString(), $gateNow->copy()->subDay()->toDateString()] as $showdate) {
            foreach ($this->phishShowsScheduledFor($showdate) as $show) {
                if ($this->nowIsInsideWindowFor($showdate, $this->timezone->resolveForShow($show))) {
                    return $showdate;
                }
            }
        }

        return null;
    }

    /**
     * Whether a scheduled Phish show's window contains the current time.
     *
     * This is the pacing signal for the sync loop: it goes true an hour before
     * a typical downbeat rather than at the first song, so the loop is already
     * polling quickly by the time setlist entries start landing.
     */
    public function inShowWindow(): bool
    {
        return $this->showdateInWindow() !== null;
    }

    /**
     * Whether a show appears to be actively underway — inside a scheduled
     * show's window *and* already carrying setlist entries upstream.
     *
     * There is no end-of-show flag, so the window is what bounds this; a
     * finished show keeps its setlist forever and would otherwise read as live
     * indefinitely.
     */
    public function showInProgress(): bool
    {
        $showdate = $this->showdateInWindow();

        if ($showdate === null) {
            return false;
        }

        return $this->client->fetchSetlistForShowdate($showdate) !== [];
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

    /**
     * Publish the snapshot the browser polls for live updates.
     *
     * The version is the hash the last year sync recorded, so it moves exactly
     * when the current year's setlist data changes and never touches the API
     * itself. The showdate is the show whose window is currently open (or null),
     * which the caller already resolved while pacing its own loop; the window
     * flag is simply whether that showdate exists.
     */
    public function publishLiveState(?string $showdate): void
    {
        $year = $this->currentShowYear();

        $version = PhishNetSyncState::query()
            ->where('key', "setlists.year.{$year}")
            ->value('hash');

        $this->repository->publishLiveState($version, $showdate !== null, $year, $showdate);
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
}
