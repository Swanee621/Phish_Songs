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
 * One show a year, each on its own tour, so a slug has a history long enough
 * for the song dialog's paging to have something to page through.
 */
function syncPerformanceYears(int $from, int $to): void
{
    $synchronizer = app(PhishNetSynchronizer::class);

    foreach (range($from, $to) as $index => $year) {
        fakeSetlistYear($year, [setlistRow([
            'showid' => 1000 + $index,
            'uniqueid' => 2000 + $index,
            'showdate' => "{$year}-07-25",
            'showyear' => $year,
            'tourid' => 300 + $index,
            'tourname' => "{$year} Tour",
        ])]);

        $synchronizer->syncYear($year);
    }
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

/**
 * @param  array<int, array<string, mixed>>  $rows
 */
function fakeScheduledShows(string $showdate, array $rows): void
{
    fakeEndpoint("shows/showdate/{$showdate}.json", $rows);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scheduledShowRow(array $overrides = []): array
{
    return array_merge([
        'showid' => 1771439079,
        'showdate' => '2026-07-19',
        'showyear' => 2026,
        'venueid' => 9,
        'venue' => 'Merriweather Post Pavilion',
        'city' => 'Columbia',
        'state' => 'MD',
        'country' => 'USA',
        'artistid' => 1,
        'artist_name' => 'Phish',
        'tourid' => 217,
        'tour_name' => '2026 Summer Tour',
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

test('the evening window maps to the same days showdate', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    expect(app(PhishNetSynchronizer::class)->showdateInWindow())->toBe('2026-07-19');
    expect(app(PhishNetSynchronizer::class)->inShowWindow())->toBeTrue();
});

test('after midnight the window still belongs to the previous days showdate', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);

    $this->travelTo('2026-07-20 00:30:00 America/New_York');

    expect(app(PhishNetSynchronizer::class)->showdateInWindow())->toBe('2026-07-19');
});

test('the live snapshot keeps the showdate but clears the live flag when the show ends', function () {
    Queue::fake();
    fakeSetlistYear(2026, [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026, 'transition' => 6]),
    ]);
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026, 'transition' => 6]),
    ]);

    $this->travelTo('2026-07-19 23:30:00 America/New_York');

    (new SyncPhishNetTour)->handle(app(PhishNetSynchronizer::class));

    $state = app(PhishNetRepository::class)->liveState();

    expect($state['inShowWindow'])->toBeFalse()
        ->and($state['showdate'])->toBe('2026-07-19');
});

test('the showdate feed lands the setlist while the year feed is still stale', function () {
    Queue::fake();

    // The bulk year feed has not refreshed yet — it still returns nothing.
    fakeSetlistYear(2026, []);
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);

    // The per-showdate feed already carries tonight's opener.
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow([
            'showdate' => '2026-07-19',
            'showyear' => 2026,
            'song' => 'Chalk Dust Torture',
            'slug' => 'chalk-dust-torture',
        ]),
    ]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    (new SyncPhishNetTour)->handle(app(PhishNetSynchronizer::class));

    // Imported straight from the showdate feed despite the empty year feed...
    expect(SetlistEntry::query()->where('slug', 'chalk-dust-torture')->exists())->toBeTrue();

    // ...and the published version follows the showdate hash so an open page refreshes.
    expect(app(PhishNetRepository::class)->liveState())
        ->version->not->toBeNull()
        ->inShowWindow->toBeTrue();
});

test('an idle run refreshes the song catalog every time, changed year or not', function () {
    Queue::fake();

    $this->travelTo('2026-07-21 12:00:00 America/New_York');

    fakeSetlistYear(2026, []);
    fakeEndpoint('songs.json', [
        ['songid' => 1, 'song' => 'Dooley', 'slug' => 'dooley', 'artist' => 'Phish', 'times_played' => 2],
    ]);

    (new SyncPhishNetTour)->handle(app(PhishNetSynchronizer::class));

    expect(Song::query()->where('slug', 'dooley')->exists())->toBeTrue();
});

test('a show night polls only tonight: one schedule lookup, one setlist fetch', function () {
    Queue::fake();
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
    ]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    (new SyncPhishNetTour)->handle(app(PhishNetSynchronizer::class));

    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'setlists/showdate/2026-07-19'))->count())->toBe(1)
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'shows/showdate/'))->count())->toBe(1);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'showyear'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'songs.json'));
});

test('a failed run re-publishes the last state without touching the API', function () {
    config(['phishnet.sync.active_interval' => 360]);
    Queue::fake();

    // The state a mid-show run had published before the upstream began failing.
    app(PhishNetRepository::class)->publishLiveState('abc123', true, 2026, '2026-07-19');

    $this->travelTo(now()->addMinutes(10));

    (new SyncPhishNetTour)->failed(new RuntimeException('upstream down'));

    $state = app(PhishNetRepository::class)->liveState();

    // The clock restamps so phish:tick waits out the interval rather than
    // re-dispatching every minute, and the window flag holds so pacing stays
    // fast through a mid-show outage.
    expect($state['inShowWindow'])->toBeTrue()
        ->and($state['updatedAt'])->toBe(now()->toIso8601String());

    Http::assertNothingSent();

    Queue::assertPushed(
        SyncPhishNetTour::class,
        fn (SyncPhishNetTour $job) => $job->delay->timestamp === now()->addSeconds(360)->timestamp,
    );
});

test('the song performances endpoint caps a page and says more is waiting', function () {
    syncPerformanceYears(2013, 2025);

    $this->getJson(route('data.song-performances', ['slug' => 'first-tube']))
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('data.0.showdate', '2025-07-25')
        ->assertJsonPath('meta.offset', 0)
        ->assertJsonPath('meta.perPage', 10)
        ->assertJsonPath('meta.hasMore', true);
});

test('the song performances endpoint picks up where an offset left off', function () {
    syncPerformanceYears(2013, 2025);

    $response = $this->getJson(route('data.song-performances', [
        'slug' => 'first-tube',
        'offset' => 10,
    ]))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.offset', 10)
        ->assertJsonPath('meta.hasMore', false);

    /** The tail of the history, with nothing repeated from the first page. */
    expect($response->json('data.*.showdate'))->toBe([
        '2015-07-25',
        '2014-07-25',
        '2013-07-25',
    ]);
});

test('the song performances endpoint can leave out the tour the dialog already lists', function () {
    fakeSetlistYear(2025, [
        setlistRow(),
        setlistRow([
            'showid' => 1739906900,
            'uniqueid' => 510500,
            'showdate' => '2025-09-01',
            'tourid' => 212,
            'tourname' => '2025 Fall Tour',
        ]),
    ]);

    app(PhishNetSynchronizer::class)->syncYear(2025);

    /** The excluded tour holds the newer show, so it would otherwise lead. */
    $this->getJson(route('data.song-performances', [
        'slug' => 'first-tube',
        'exclude_tour' => 212,
    ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.showdate', '2025-07-25');
});

test('the tick command holds off while the last sync is still within the interval', function () {
    config(['phishnet.sync.interval' => 3600]);
    Queue::fake();

    $this->travelTo('2026-07-19 12:00:00');
    app(PhishNetRepository::class)->publishLiveState(null, false, 2026);

    // Half an hour on, well inside the 3600s idle interval.
    $this->travelTo('2026-07-19 12:30:00');
    $this->artisan('phish:tick')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('the tick command uses the shorter active interval while a show is underway', function () {
    config([
        'phishnet.sync.interval' => 3600,
        'phishnet.sync.active_interval' => 360,
    ]);
    Queue::fake();

    $this->travelTo('2026-07-19 20:00:00');
    app(PhishNetRepository::class)->publishLiveState(null, true, 2026);

    // Ten minutes on: past the 360s active interval, far short of the 3600s idle one.
    $this->travelTo('2026-07-19 20:10:00');
    $this->artisan('phish:tick')->assertSuccessful();

    Queue::assertPushed(SyncPhishNetTour::class);
});

test('the live endpoint serves the active poll interval and showdate during a show window', function () {
    config(['phishnet.client.interval' => 3600, 'phishnet.client.active_interval' => 60]);

    app(PhishNetRepository::class)->publishLiveState('abc123', true, 2026, '2026-07-19');

    $this->getJson(route('data.live'))
        ->assertOk()
        ->assertJson(['data' => [
            'inShowWindow' => true,
            'pollInterval' => 60,
            'showdate' => '2026-07-19',
        ]]);
});
