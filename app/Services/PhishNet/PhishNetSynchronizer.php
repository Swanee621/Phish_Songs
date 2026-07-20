<?php

namespace App\Services\PhishNet;

use App\Models\PhishNetSyncState;
use App\Models\Show;
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
            $this->repository->forgetVenues();
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
