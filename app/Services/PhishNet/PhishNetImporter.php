<?php

namespace App\Services\PhishNet;

use App\Models\SetlistEntry;
use App\Models\Show;
use App\Models\Song;
use App\Models\Tour;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;

/**
 * Writes raw phish.net API payloads into the local database.
 *
 * Every import is idempotent: rows are upserted by their upstream primary key,
 * and rows that have disappeared from the payload are removed so upstream
 * setlist corrections propagate instead of leaving orphans behind.
 */
class PhishNetImporter
{
    /**
     * Import every setlist row belonging to a single show year.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function importSetlistYear(int $year, array $rows): void
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
    public function importSetlistShowdate(array $rows): void
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
    public function importSongs(array $rows): void
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
    public function importVenues(array $rows): void
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
}
