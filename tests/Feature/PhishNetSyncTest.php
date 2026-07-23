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
use App\Services\PhishNet\VenueTimezone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

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

test('the schedule is not even fetched outside the eastern gate', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);

    $this->travelTo('2026-07-19 14:00:00 America/New_York');

    expect(app(PhishNetSynchronizer::class)->showdateInWindow())->toBeNull();

    Http::assertNothingSent();
});

test('an eastern show is not live an hour before its window opens', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);

    $this->travelTo('2026-07-19 18:30:00 America/New_York');

    expect(app(PhishNetSynchronizer::class)->showdateInWindow())->toBeNull();
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

test('a pacific show is still live at an hour that an eastern show would have ended', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow([
        'state' => 'CA', 'city' => 'Los Angeles', 'venue' => 'Hollywood Bowl',
    ])]);

    /** 23:30 Pacific — a full 02:30 Eastern, past any east coast show. */
    $this->travelTo('2026-07-19 23:30:00 America/Los_Angeles');

    expect(app(PhishNetSynchronizer::class)->showdateInWindow())->toBe('2026-07-19');
});

test('a pacific show is not yet live when the eastern clock is already past 7pm', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow([
        'state' => 'CA', 'city' => 'Los Angeles', 'venue' => 'Hollywood Bowl',
    ])]);

    /** 19:30 Eastern is only 16:30 in Los Angeles, hours before doors. */
    $this->travelTo('2026-07-19 19:30:00 America/New_York');

    expect(app(PhishNetSynchronizer::class)->showdateInWindow())->toBeNull();
});

test('a mountain time show resolves to its own window', function () {
    fakeScheduledShows('2026-09-04', [scheduledShowRow([
        'showdate' => '2026-09-04', 'state' => 'CO', 'city' => 'Commerce City',
        'venue' => "Dick's Sporting Goods Park",
    ])]);

    $this->travelTo('2026-09-04 21:00:00 America/Denver');

    expect(app(PhishNetSynchronizer::class)->showdateInWindow())->toBe('2026-09-04');
});

test('a side project show on the same date does not count as a phish show', function () {
    fakeScheduledShows('2026-07-19', [
        scheduledShowRow(['artistid' => 6, 'artist_name' => 'Mike Gordon']),
    ]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    expect(app(PhishNetSynchronizer::class)->inShowWindow())->toBeFalse();
});

test('a show is only in progress once setlist entries appear', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    $synchronizer = app(PhishNetSynchronizer::class);

    expect($synchronizer->showInProgress())->toBeFalse();

    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
    ]);

    expect($synchronizer->showInProgress())->toBeTrue();
});

test('a finished show does not read as in progress once the window closes', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
    ]);

    $this->travelTo('2026-07-20 11:00:00 America/New_York');

    expect(app(PhishNetSynchronizer::class)->showInProgress())->toBeFalse();
});

test('a show drops out of the live window once its final song is marked', function () {
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);

    $this->travelTo('2026-07-19 22:30:00 America/New_York');

    $synchronizer = app(PhishNetSynchronizer::class);

    expect($synchronizer->inShowWindow())->toBeTrue();

    // The closing song of the night is the one tagged with transition 6.
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026]),
        setlistRow([
            'uniqueid' => 510500, 'position' => 20, 'set' => 'e',
            'song' => 'Tweezer Reprise', 'slug' => 'tweezer-reprise',
            'transition' => 6, 'showdate' => '2026-07-19', 'showyear' => 2026,
        ]),
    ]);

    expect($synchronizer->inShowWindow())->toBeFalse()
        // The scheduled showdate lingers so an open page can still fetch that last song.
        ->and($synchronizer->showdateInWindow())->toBe('2026-07-19');
});

test('the sync loop backs off to the idle interval once the final song is marked', function () {
    config([
        'phishnet.sync.interval' => 3600,
        'phishnet.sync.active_interval' => 360,
    ]);

    Queue::fake();
    fakeSetlistYear(2026, []);
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);
    fakeEndpoint('setlists/showdate/2026-07-19.json', [
        setlistRow(['showdate' => '2026-07-19', 'showyear' => 2026, 'transition' => 6]),
    ]);

    $this->travelTo('2026-07-19 23:30:00 America/New_York');

    (new SyncPhishNetTour)->handle(app(PhishNetSynchronizer::class));

    Queue::assertPushed(SyncPhishNetTour::class, function (SyncPhishNetTour $job) {
        return $job->delay->timestamp === now()->addSeconds(3600)->timestamp;
    });
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

test('venue timezones resolve across the four mainland zones', function () {
    $timezone = app(VenueTimezone::class);

    expect($timezone->resolve('NY'))->toBe('America/New_York')
        ->and($timezone->resolve('TX'))->toBe('America/Chicago')
        ->and($timezone->resolve('CO'))->toBe('America/Denver')
        ->and($timezone->resolve('WA'))->toBe('America/Los_Angeles')
        ->and($timezone->resolve('AZ'))->toBe('America/Phoenix');
});

test('an unknown or blank venue state falls back to eastern', function () {
    $timezone = app(VenueTimezone::class);

    expect($timezone->resolve(''))->toBe('America/New_York')
        ->and($timezone->resolve(null))->toBe('America/New_York')
        ->and($timezone->resolveForShow(['country' => 'Japan']))->toBe('America/New_York');
});

test('the sync loop tightens its interval during a show window', function () {
    config([
        'phishnet.sync.interval' => 3600,
        'phishnet.sync.active_interval' => 360,
    ]);

    Queue::fake();
    fakeSetlistYear(2026, []);
    fakeScheduledShows('2026-07-19', [scheduledShowRow()]);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    (new SyncPhishNetTour)->handle(app(PhishNetSynchronizer::class));

    Queue::assertPushed(SyncPhishNetTour::class, function (SyncPhishNetTour $job) {
        return $job->delay->timestamp === now()->addSeconds(360)->timestamp;
    });
});

test('the sync loop backs off to the idle interval with no show scheduled', function () {
    config([
        'phishnet.sync.interval' => 3600,
        'phishnet.sync.active_interval' => 360,
    ]);

    Queue::fake();
    fakeSetlistYear(2026, []);

    $this->travelTo('2026-07-19 21:30:00 America/New_York');

    (new SyncPhishNetTour)->handle(app(PhishNetSynchronizer::class));

    Queue::assertPushed(SyncPhishNetTour::class, function (SyncPhishNetTour $job) {
        return $job->delay->timestamp === now()->addSeconds(3600)->timestamp;
    });
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

test('the live endpoint serves the published version and idle poll interval', function () {
    config(['phishnet.client.interval' => 3600, 'phishnet.client.active_interval' => 60]);

    app(PhishNetRepository::class)->publishLiveState('abc123', false, 2026);

    $this->getJson(route('data.live'))
        ->assertOk()
        ->assertJson(['data' => [
            'version' => 'abc123',
            'year' => 2026,
            'inShowWindow' => false,
            'pollInterval' => 3600,
        ]]);
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

test('the live endpoint reports no version before any sync has run', function () {
    $this->getJson(route('data.live'))
        ->assertOk()
        ->assertJson(['data' => ['version' => null, 'inShowWindow' => false]]);
});

test('the recent setlists page passes the client poll intervals to the browser', function () {
    config([
        'phishnet.client.interval' => 3600,
        'phishnet.client.active_interval' => 60,
    ]);

    $this->get(route('recent-setlists'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecentSetlists')
            ->where('clientSyncInterval', 3600)
            ->where('clientSyncActiveInterval', 60),
        );
});

test('the setlist browser page passes the client poll intervals to the browser', function () {
    config([
        'phishnet.client.interval' => 3600,
        'phishnet.client.active_interval' => 60,
    ]);

    $this->get(route('setlist-browser'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SetlistBrowser')
            ->where('clientSyncInterval', 3600)
            ->where('clientSyncActiveInterval', 60),
        );
});
