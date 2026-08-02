<?php

use App\Models\PhishNetSyncState;
use App\Models\SetlistEntry;
use App\Models\Show;
use App\Models\Song;
use App\Models\Tour;
use App\Models\Venue;
use App\Services\PhishNet\PhishNetClient;
use App\Services\PhishNet\PhishNetRepository;
use App\Services\PhishNet\PhishNetSynchronizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
    fakeSetlistYear(2026, [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026, 'transition' => 6]),
    ]);
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026, 'transition' => 6]),
    ]);

    $this->travelTo('2026-07-19 23:30:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    $state = app(PhishNetRepository::class)->liveState();

    expect($state['inShowWindow'])->toBeFalse()
        ->and($state['showdate'])->toBe('2026-07-19');
});

test('the showdate feed lands the setlist while the year feed is still stale', function () {
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

    app(PhishNetSynchronizer::class)->syncPass();

    // Imported straight from the showdate feed despite the empty year feed...
    expect(SetlistEntry::query()->where('slug', 'chalk-dust-torture')->exists())->toBeTrue();

    // ...and the published version follows the showdate hash so an open page refreshes.
    expect(app(PhishNetRepository::class)->liveState())
        ->version->not->toBeNull()
        ->inShowWindow->toBeTrue();
});

test('a first idle run checks todays feed and seeds the year and song catalogs', function () {
    $this->travelTo('2026-07-21 12:00:00 America/New_York');

    fakeSetlistYear(2026, []);
    fakeEndpoint('songs.json', [
        ['songid' => 1, 'song' => 'Dooley', 'slug' => 'dooley', 'artist' => 'Phish', 'times_played' => 2],
    ]);

    app(PhishNetSynchronizer::class)->syncPass();

    expect(Song::query()->where('slug', 'dooley')->exists())->toBeTrue();

    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'setlists/showdate/2026-07-21'))->count())->toBe(1);
});

test('an idle run checks today and the songs hourly but leaves the year feed alone', function () {
    $this->travelTo('2026-07-21 12:00:00 America/New_York');

    fakeSetlistYear(2026, []);
    fakeEndpoint('songs.json', [
        ['songid' => 1, 'song' => 'Dooley', 'slug' => 'dooley', 'artist' => 'Phish', 'times_played' => 2],
    ]);

    // The first run seeds the year feed; the second, half an hour on, finds
    // it fresh and re-checks only the cheap hourly feeds.
    app(PhishNetSynchronizer::class)->syncPass();

    $this->travelTo('2026-07-21 12:30:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'setlists/showdate/2026-07-21'))->count())->toBe(2)
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'songs.json'))->count())->toBe(2)
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'showyear'))->count())->toBe(1);
});

test('the year feed refreshes once a day while idle', function () {
    $this->travelTo('2026-07-19 12:00:00 America/New_York');

    fakeSetlistYear(2026, []);

    app(PhishNetSynchronizer::class)->syncPass();

    // The next day, past the 24h catalog interval, it is re-pulled.
    $this->travelTo('2026-07-20 13:00:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'showyear'))->count())->toBe(2);
});

test('the day after a show its feed is still polled for corrections', function () {
    // Noon on show day: an idle run seeds the year feed's daily clock.
    $this->travelTo('2026-07-19 12:00:00 America/New_York');

    fakeSetlistYear(2026, []);

    app(PhishNetSynchronizer::class)->syncPass();

    // That night the show lands through the live branch.
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
    ]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    // The next day an editor appends the encore — final-song marker and all —
    // that the live feed missed.
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
        setlistRow([
            'showdate' => '2026-07-19',
            'showyear' => 2026,
            'uniqueid' => 510999,
            'position' => 2,
            'set' => 'e',
            'song' => 'Slave to the Traffic Light',
            'slug' => 'slave-to-the-traffic-light',
            'transition' => 6,
        ]),
    ]);

    $this->travelTo('2026-07-20 12:00:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    expect(SetlistEntry::query()->where('slug', 'slave-to-the-traffic-light')->exists())->toBeTrue();

    // Two days on, the show counts as settled — older than a day, final song
    // stored — and its feed is left to the daily year refresh. Three fetches
    // in total: show-day noon (as today's feed), the live poll that night, and
    // the day-after correction poll.
    $this->travelTo('2026-07-21 12:00:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'setlists/showdate/2026-07-19'))->count())->toBe(3);
});

test('another artists closing song does not end phishs show early', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);

    // A side project playing the same night has finished its set (transition
    // 6) while Phish is two songs into theirs.
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
        setlistRow([
            'showdate' => '2026-07-19',
            'showyear' => 2026,
            'showid' => 1739906999,
            'uniqueid' => 510888,
            'artistid' => 7,
            'transition' => 6,
            'song' => 'Money Love and Change',
            'slug' => 'money-love-and-change',
        ]),
    ]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    // Phish's show is still going, so pacing stays on the show-night interval.
    expect(app(PhishNetRepository::class)->liveState()['inShowWindow'])->toBeTrue();
});

test('a stale live snapshot does not hijack the pass after an outage', function () {
    // Published mid-show, right before the process running the loop dies.
    $this->travelTo('2026-07-19 21:30:00 America/New_York');
    app(PhishNetRepository::class)->publishLiveState('abc123', true, 2026, '2026-07-19');

    // Three days later the loop comes back during gate hours. The schedule
    // shows nothing tonight, and the days-old "live" snapshot must not send
    // the pass to the old show's feed as if that show were still going.
    $this->travelTo('2026-07-22 20:00:00 America/New_York');

    fakeSetlistYear(2026, []);

    app(PhishNetSynchronizer::class)->syncPass();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'setlists/showdate/2026-07-19'));

    // The pass ran as an idle catch-up instead and cleared the stale flag.
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'setlists/showdate/2026-07-22'))->count())->toBe(1)
        ->and(app(PhishNetRepository::class)->liveState()['inShowWindow'])->toBeFalse();
});

test('a half-imported show keeps its feed polled until its final song lands', function () {
    // Noon on show day: an idle pass seeds the year feed's daily clock.
    $this->travelTo('2026-07-19 12:00:00 America/New_York');

    fakeSetlistYear(2026, []);

    app(PhishNetSynchronizer::class)->syncPass();

    // That night the live loop catches the opener, then the process running it
    // dies mid-show: the stored setlist never gets its final-song marker.
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
    ]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    // Three days later the loop comes back. The show is well past the
    // day-after window, but its setlist is visibly unfinished, so the idle
    // pass re-polls its feed instead of waiting on the daily year refresh.
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
        setlistRow([
            'showdate' => '2026-07-19',
            'showyear' => 2026,
            'uniqueid' => 510999,
            'position' => 2,
            'set' => 'e',
            'song' => 'Slave to the Traffic Light',
            'slug' => 'slave-to-the-traffic-light',
            'transition' => 6,
        ]),
    ]);

    $this->travelTo('2026-07-22 12:00:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    expect(SetlistEntry::query()->where('slug', 'slave-to-the-traffic-light')->exists())->toBeTrue();

    // With the final song stored the show is settled, so the next pass leaves
    // its feed alone. Three fetches: show-day noon (as today's feed), the live
    // poll that night, and the completeness catch-up.
    $this->travelTo('2026-07-22 13:00:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'setlists/showdate/2026-07-19'))->count())->toBe(3);
});

test('a show landing in todays feed outside the window is imported', function () {
    $this->travelTo('2026-07-21 12:00:00 America/New_York');

    fakeSetlistYear(2026, []);
    fakeEndpoint('songs.json', [
        ['songid' => 1, 'song' => 'Dooley', 'slug' => 'dooley', 'artist' => 'Phish', 'times_played' => 2],
    ]);

    app(PhishNetSynchronizer::class)->syncPass();

    // An hour later a matinee's opener appears in today's feed — no scheduled
    // show window is open, so only the idle branch can catch it.
    fakeEndpoint('setlists/showdate/2026-07-21.json', [
        setlistRow([
            'showdate' => '2026-07-21',
            'showyear' => 2026,
            'song' => 'Chalk Dust Torture',
            'slug' => 'chalk-dust-torture',
        ]),
    ]);

    $this->travelTo('2026-07-21 13:00:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    expect(SetlistEntry::query()->where('slug', 'chalk-dust-torture')->exists())->toBeTrue();

    // The year feed stays on its daily cadence — today's feed alone carried
    // the show in.
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'showyear'))->count())->toBe(1);
});

test('a show night polls only tonight: one schedule lookup, one setlist fetch', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
    ]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    app(PhishNetSynchronizer::class)->syncPass();

    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'setlists/showdate/2026-07-19'))->count())->toBe(1)
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'shows/showdate/'))->count())->toBe(1);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'showyear'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'songs.json'));
});

test('a failing pass re-publishes the last state and waits out the interval', function () {
    config(['phishnet.sync.active_interval' => 360]);

    // Every upstream call refuses us for the length of this test.
    $this->mock(PhishNetClient::class, function ($mock) {
        $mock->shouldReceive('fetchShowsForDate', 'fetchSetlistForShowdate', 'fetchSongs', 'fetchSetlistsForYear')
            ->andThrow(new RuntimeException('upstream down'));
    });

    // The state a mid-show run had published before the upstream began failing.
    $this->travelTo('2026-07-19 20:00:00 America/New_York');
    app(PhishNetRepository::class)->publishLiveState('abc123', true, 2026, '2026-07-19');

    $this->travelTo('2026-07-19 20:10:00 America/New_York');

    $this->artisan('phish:tick')->assertFailed();

    $state = app(PhishNetRepository::class)->liveState();

    // The clock restamps so the next tick waits out the interval rather than
    // hammering the failing upstream every minute, and the window flag holds
    // so pacing stays fast through a mid-show outage.
    expect($state['inShowWindow'])->toBeTrue()
        ->and($state['showdate'])->toBe('2026-07-19')
        ->and($state['updatedAt'])->toBe(now()->toIso8601String());

    // Two minutes on — well inside the restamped interval — the tick holds
    // off entirely, never reaching the client that would throw again.
    $this->travelTo('2026-07-19 20:12:00 America/New_York');

    $this->artisan('phish:tick')->assertSuccessful();
});

test('an import refreshes the cached year, showdate and year-list payloads', function () {
    fakeSetlistYear(2025, [setlistRow()]);

    $synchronizer = app(PhishNetSynchronizer::class);
    $repository = app(PhishNetRepository::class);

    $synchronizer->syncYear(2025);

    // Warm every read-through cache.
    expect($repository->setlistsForYear(2025))->toHaveCount(1)
        ->and($repository->setlistForShowdate('2025-07-25'))->toHaveCount(1)
        ->and($repository->showYears())->toHaveCount(1);

    // An encore lands upstream.
    fakeSetlistYear(2025, [
        setlistRow(),
        setlistRow([
            'uniqueid' => 510999,
            'position' => 2,
            'set' => 'e',
            'song' => 'Slave to the Traffic Light',
            'slug' => 'slave-to-the-traffic-light',
        ]),
    ]);

    $synchronizer->syncYear(2025);

    expect($repository->setlistsForYear(2025))->toHaveCount(2)
        ->and($repository->setlistForShowdate('2025-07-25'))->toHaveCount(2);
});

test('a stale payload written back after an import is not served', function () {
    fakeSetlistYear(2025, [setlistRow()]);

    $synchronizer = app(PhishNetSynchronizer::class);
    $repository = app(PhishNetRepository::class);

    $synchronizer->syncYear(2025);

    // What a request that queried just before the next import would hold, and
    // the version its cache key would carry.
    $staleRows = $repository->setlistForShowdate('2025-07-25');
    $staleVersion = Cache::get('phishnet.version.year.2025');

    fakeSetlistYear(2025, [
        setlistRow(),
        setlistRow([
            'uniqueid' => 510999,
            'position' => 2,
            'set' => 'e',
            'song' => 'Slave to the Traffic Light',
            'slug' => 'slave-to-the-traffic-light',
        ]),
    ]);

    $synchronizer->syncYear(2025);

    /*
     * The slow request now writes its pre-import payload back — the
     * interleaving that, under forget-based invalidation, re-cached a
     * half-finished setlist into the freshly cleared key and pinned it there.
     * Under versioned keys it lands under the old version, which nothing
     * reads again.
     */
    Cache::forever("phishnet.setlists.showdate.2025-07-25.{$staleVersion}", $staleRows);

    expect($repository->setlistForShowdate('2025-07-25'))->toHaveCount(2);
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
    $this->travelTo('2026-07-19 12:00:00');
    app(PhishNetRepository::class)->publishLiveState(null, false, 2026);

    // Half an hour on, well inside the 3600s idle interval.
    $this->travelTo('2026-07-19 12:30:00');
    $this->artisan('phish:tick')->assertSuccessful();

    Http::assertNothingSent();
});

test('the tick command uses the shorter active interval while a show is underway', function () {
    config([
        'phishnet.sync.interval' => 3600,
        'phishnet.sync.active_interval' => 360,
    ]);
    fakeSetlistYear(2026, []);

    $this->travelTo('2026-07-19 20:00:00');
    app(PhishNetRepository::class)->publishLiveState(null, true, 2026);

    // Ten minutes on: past the 360s active interval, far short of the 3600s
    // idle one, so this tick runs a pass inline — no queue in between.
    $this->travelTo('2026-07-19 20:10:00');
    $this->artisan('phish:tick')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'setlists/showdate/2026-07-19'));
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
