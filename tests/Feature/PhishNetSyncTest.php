<?php

use App\Jobs\SyncPhishNetTour;
use App\Models\PhishNetSyncState;
use App\Models\SetlistEntry;
use App\Models\Show;
use App\Models\Song;
use App\Models\Tour;
use App\Models\Venue;
use App\Services\PhishNet\PhishNetRepository;
use App\Services\PhishNet\PhishNetSynchronizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Http::fake() merges successive stubs rather than replacing them, and the
 * first match wins — which makes it useless for tests that need a *second*,
 * different response from the same endpoint. So a single fake is installed once
 * per test and dispatches against a payload map the tests can rewrite freely.
 */
beforeEach(function () {
    $store = new stdClass;
    $store->payloads = [];

    app()->instance('test.phishnet.payloads', $store);

    Http::fake(function ($request) {
        foreach (app('test.phishnet.payloads')->payloads as $path => $rows) {
            if (str_contains($request->url(), $path)) {
                return Http::response(['data' => $rows]);
            }
        }

        return Http::response(['data' => []]);
    });
});

/**
 * @param  array<int, array<string, mixed>>  $rows
 */
function fakeEndpoint(string $path, array $rows): void
{
    app('test.phishnet.payloads')->payloads[$path] = $rows;
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 */
function fakeSetlistYear(int $year, array $rows): void
{
    fakeEndpoint("setlists/showyear/{$year}.json", $rows);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function setlistRow(array $overrides = []): array
{
    return array_merge([
        'showid' => 1739906822,
        'showdate' => '2025-07-25',
        'showyear' => 2025,
        'uniqueid' => 510412,
        'permalink' => 'https://phish.net/setlists/example.html',
        'setlistnotes' => '<p>Notes.</p>',
        'songid' => 202,
        'position' => 1,
        'transition' => 1,
        'set' => '1',
        'song' => 'First Tube',
        'slug' => 'first-tube',
        'trans_mark' => ', ',
        'gap' => 3,
        'tourid' => 211,
        'tourname' => '2025 Early Summer Tour',
        'tourwhen' => '2025 Summer',
        'venueid' => 1588,
        'venue' => 'Broadview Stage at SPAC',
        'city' => 'Saratoga Springs',
        'state' => 'NY',
        'country' => 'USA',
        'artistid' => 1,
        'is_original' => 1,
    ], $overrides);
}

test('a year sync imports shows, venues, tours and setlist entries', function () {
    fakeSetlistYear(2025, [setlistRow()]);

    $changed = app(PhishNetSynchronizer::class)->syncYear(2025);

    expect($changed)->toBeTrue();

    expect(Venue::find(1588)?->venuename)->toBe('Broadview Stage at SPAC');
    expect(Tour::find(211)?->tourname)->toBe('2025 Early Summer Tour');
    expect(Show::find(1739906822)?->showyear)->toBe(2025);
    expect(SetlistEntry::find(510412)?->slug)->toBe('first-tube');
});

test('an unchanged payload is not re-imported and reports no change', function () {
    fakeSetlistYear(2025, [setlistRow()]);

    $synchronizer = app(PhishNetSynchronizer::class);

    expect($synchronizer->syncYear(2025))->toBeTrue();

    $importedAt = PhishNetSyncState::where('key', 'setlists.year.2025')->first()->changed_at;

    $this->travel(5)->minutes();

    expect($synchronizer->syncYear(2025))->toBeFalse();

    $state = PhishNetSyncState::where('key', 'setlists.year.2025')->first();

    expect($state->changed_at->timestamp)->toBe($importedAt->timestamp)
        ->and($state->checked_at->timestamp)->toBeGreaterThan($importedAt->timestamp);
});

test('a changed payload updates existing rows and drops withdrawn entries', function () {
    fakeSetlistYear(2025, [
        setlistRow(),
        setlistRow(['uniqueid' => 510413, 'position' => 2, 'song' => 'Bathtub Gin', 'slug' => 'bathtub-gin']),
    ]);

    $synchronizer = app(PhishNetSynchronizer::class);
    $synchronizer->syncYear(2025);

    expect(SetlistEntry::count())->toBe(2);

    fakeSetlistYear(2025, [setlistRow(['song' => 'First Tube Reprise'])]);

    expect($synchronizer->syncYear(2025))->toBeTrue();

    expect(SetlistEntry::count())->toBe(1)
        ->and(SetlistEntry::find(510412)->song)->toBe('First Tube Reprise')
        ->and(SetlistEntry::find(510413))->toBeNull();
});

test('a show removed upstream is deleted from the year', function () {
    fakeSetlistYear(2025, [
        setlistRow(),
        setlistRow(['showid' => 999, 'uniqueid' => 888, 'showdate' => '2025-08-01']),
    ]);

    $synchronizer = app(PhishNetSynchronizer::class);
    $synchronizer->syncYear(2025);

    expect(Show::count())->toBe(2);

    fakeSetlistYear(2025, [setlistRow()]);
    $synchronizer->syncYear(2025);

    expect(Show::count())->toBe(1)
        ->and(Show::find(999))->toBeNull();
});

test('syncing a year invalidates the cached payload for that year', function () {
    $repository = app(PhishNetRepository::class);

    fakeSetlistYear(2025, [setlistRow()]);
    app(PhishNetSynchronizer::class)->syncYear(2025);

    expect($repository->setlistsForYear(2025))->toHaveCount(1);
    expect(Cache::has('phishnet.setlists.year.2025'))->toBeTrue();

    fakeSetlistYear(2025, [
        setlistRow(),
        setlistRow(['uniqueid' => 510413, 'position' => 2, 'song' => 'Bathtub Gin', 'slug' => 'bathtub-gin']),
    ]);
    app(PhishNetSynchronizer::class)->syncYear(2025);

    expect($repository->setlistsForYear(2025))->toHaveCount(2);
});

test('the song catalog sync de-dupes by slug and prunes removed songs', function () {
    fakeEndpoint('songs.json', [
        ['songid' => 1, 'song' => 'Dooley', 'slug' => 'dooley', 'artist' => 'Phish', 'times_played' => 2],
        ['songid' => 1, 'song' => 'Dooley', 'slug' => 'dooley', 'artist' => 'Phish', 'times_played' => 2],
        ['songid' => 2, 'song' => 'Tweezer', 'slug' => 'tweezer', 'artist' => 'Phish', 'times_played' => 400],
    ]);

    $synchronizer = app(PhishNetSynchronizer::class);
    expect($synchronizer->syncSongs())->toBeTrue();
    expect(Song::count())->toBe(2);

    fakeEndpoint('songs.json', [
        ['songid' => 2, 'song' => 'Tweezer', 'slug' => 'tweezer', 'artist' => 'Phish', 'times_played' => 401],
    ]);

    expect($synchronizer->syncSongs())->toBeTrue();
    expect(Song::count())->toBe(1)
        ->and(Song::find(2)->times_played)->toBe(401);
});

test('the current show year falls back to the latest stored year before the new year has shows', function () {
    Show::factory()->create(['showdate' => '2025-12-31', 'showyear' => 2025]);

    $this->travelTo('2026-01-04');

    expect(app(PhishNetSynchronizer::class)->currentShowYear())->toBe(2025);
});

test('the current tour is taken from the most recent show', function () {
    $tour = Tour::factory()->create(['tourname' => '2025 Early Summer Tour']);
    Show::factory()->create(['showdate' => '2025-06-01', 'showyear' => 2025]);
    Show::factory()->create(['showdate' => '2025-07-25', 'showyear' => 2025, 'tourid' => $tour->tourid]);

    expect(app(PhishNetSynchronizer::class)->currentTour())
        ->toMatchArray(['tourid' => $tour->tourid, 'tourname' => '2025 Early Summer Tour', 'year' => 2025]);
});

test('the sync job re-dispatches itself on the configured interval', function () {
    config(['phishnet.sync.interval' => 90]);

    Queue::fake();
    fakeSetlistYear((int) now()->year, []);

    (new SyncPhishNetTour)->handle(app(PhishNetSynchronizer::class));

    Queue::assertPushed(SyncPhishNetTour::class);
});

test('a one-off sync job does not re-dispatch itself', function () {
    Queue::fake();
    fakeSetlistYear((int) now()->year, []);

    (new SyncPhishNetTour(continuous: false))->handle(app(PhishNetSynchronizer::class));

    Queue::assertNothingPushed();
});

test('the watch command starts the loop', function () {
    Queue::fake();

    $this->artisan('phish:watch')->assertSuccessful();

    Queue::assertPushed(SyncPhishNetTour::class);
});

test('a single sync run imports the live year only', function () {
    fakeSetlistYear(2025, [setlistRow()]);

    $this->travelTo('2025-08-01');

    $this->artisan('phish:sync --year=2025')->assertSuccessful();

    expect(Show::count())->toBe(1);
});
