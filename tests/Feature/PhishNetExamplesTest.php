<?php

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the jam chart explorer page renders', function () {
    $this->get(route('jam-charts'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('examples/JamChartExplorer'));
});

test('the recent setlists page renders', function () {
    $this->get(route('recent-setlists'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('examples/RecentSetlists'));
});

test('the setlist browser page renders', function () {
    $this->get(route('setlist-browser'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('examples/SetlistBrowser'));
});

test('the venue explorer page renders', function () {
    $this->get(route('venues'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('examples/VenueExplorer'));
});

test('the tour explorer page renders', function () {
    $this->get(route('tour-explorer'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('examples/TourExplorer'));
});

test('jam charts data is proxied server-side with the api key attached', function () {
    Http::fake([
        'api.phish.net/v5/jamcharts.json*' => Http::response([
            'data' => [['slug' => 'tweezer', 'song' => 'Tweezer']],
        ]),
    ]);

    $this->getJson(route('data.jam-charts'))
        ->assertOk()
        ->assertJson(['data' => [['slug' => 'tweezer', 'song' => 'Tweezer']]]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.phish.net/v5/jamcharts.json?apikey='.config('services.phishnet.key'));
});

test('jam chart data for a slug is proxied server-side', function () {
    Http::fake([
        'api.phish.net/v5/jamcharts/slug/tweezer.json*' => Http::response([
            'data' => [['showdate' => '1997-11-22', 'jamchart_description' => 'A classic.']],
        ]),
    ]);

    $this->getJson(route('data.jam-chart', ['slug' => 'tweezer']))
        ->assertOk()
        ->assertJsonPath('data.0.showdate', '1997-11-22');
});

test('setlist for a date is proxied server-side', function () {
    Http::fake([
        'api.phish.net/v5/setlists/showdate/1997-11-22.json*' => Http::response([
            'data' => [['showdate' => '1997-11-22', 'song' => 'Tweezer']],
        ]),
    ]);

    $this->getJson(route('data.setlist', ['showdate' => '1997-11-22']))
        ->assertOk()
        ->assertJsonPath('data.0.song', 'Tweezer');
});

test('an invalid showdate format is rejected', function () {
    $this->getJson('/data/setlists/not-a-date')->assertNotFound();
});

test('venues are proxied server-side', function () {
    Http::fake([
        'api.phish.net/v5/venues.json*' => Http::response([
            'data' => [['venueid' => 1, 'venuename' => "Nectar's"]],
        ]),
    ]);

    $this->getJson(route('data.venues'))
        ->assertOk()
        ->assertJsonPath('data.0.venuename', "Nectar's");
});

test('shows for a venue are proxied server-side', function () {
    Http::fake([
        'api.phish.net/v5/shows/venueid/1.json*' => Http::response([
            'data' => [['showdate' => '1997-11-22', 'artistid' => 1]],
        ]),
    ]);

    $this->getJson(route('data.venue-shows', ['venue' => 1]))
        ->assertOk()
        ->assertJsonPath('data.0.showdate', '1997-11-22');
});

test('the song catalog is proxied server-side', function () {
    Http::fake([
        'api.phish.net/v5/songs.json*' => Http::response([
            'data' => [['slug' => 'tweezer', 'song' => 'Tweezer', 'times_played' => 400]],
        ]),
    ]);

    $this->getJson(route('data.songs'))
        ->assertOk()
        ->assertJsonPath('data.0.song', 'Tweezer');
});

test('duplicate songs from the upstream catalog are de-duped by slug', function () {
    Http::fake([
        'api.phish.net/v5/songs.json*' => Http::response([
            'data' => [
                ['slug' => 'dooley', 'song' => 'Dooley', 'times_played' => 2],
                ['slug' => 'dooley', 'song' => 'Dooley', 'times_played' => 2],
                ['slug' => 'tweezer', 'song' => 'Tweezer', 'times_played' => 400],
            ],
        ]),
    ]);

    $this->getJson(route('data.songs'))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('the tour explorer page shares the excluded songs config', function () {
    config(['services.phishnet.excluded_songs' => ['jam']]);

    $this->get(route('tour-explorer'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('examples/TourExplorer')
            ->where('excludedSongs', ['jam'])
        );
});

test('the tour explorer page shares the default minimum times played config', function () {
    config(['app.default_min_played' => 25]);

    $this->get(route('tour-explorer'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('examples/TourExplorer')
            ->where('defaultMinPlayed', 25)
        );
});
