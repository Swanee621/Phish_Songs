<?php

use App\Models\SetlistEntry;
use App\Models\Show;
use App\Models\Song;
use App\Models\Tour;
use App\Models\Venue;
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
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SongExplorer'));
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

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.phish.net/v5/jamcharts.json?apikey='.config('services.phishnet.key')));
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

test('setlist data is served from the database without calling the api', function () {
    Http::preventStrayRequests();

    $venue = Venue::factory()->create(['venuename' => "Nectar's", 'city' => 'Burlington']);
    $tour = Tour::factory()->create(['tourname' => '1997 Fall Tour', 'tourwhen' => '1997 Fall']);
    $show = Show::factory()->create([
        'showdate' => '1997-11-22',
        'showyear' => 1997,
        'venueid' => $venue->venueid,
        'tourid' => $tour->tourid,
    ]);

    SetlistEntry::factory()->create([
        'showid' => $show->showid,
        'song' => 'Tweezer',
        'slug' => 'tweezer',
        'position' => 1,
    ]);

    $this->getJson(route('data.setlist', ['showdate' => '1997-11-22']))
        ->assertOk()
        ->assertJsonPath('data.0.song', 'Tweezer')
        ->assertJsonPath('data.0.venue', "Nectar's")
        ->assertJsonPath('data.0.tourname', '1997 Fall Tour')
        ->assertJsonPath('data.0.tourwhen', '1997 Fall');
});

test('setlists for a year are served from the database in show and set order', function () {
    Http::preventStrayRequests();

    $show = Show::factory()->create(['showdate' => '1997-11-22', 'showyear' => 1997]);

    SetlistEntry::factory()->create(['showid' => $show->showid, 'song' => 'Reba', 'position' => 2]);
    SetlistEntry::factory()->create(['showid' => $show->showid, 'song' => 'Tweezer', 'position' => 1]);

    $this->getJson(route('data.setlists-for-year', ['year' => 1997]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.song', 'Tweezer')
        ->assertJsonPath('data.1.song', 'Reba');
});

test('an invalid showdate format is rejected', function () {
    $this->getJson('/data/setlists/not-a-date')->assertNotFound();
});

test('venues are served from the database', function () {
    Http::preventStrayRequests();

    Venue::factory()->create(['venuename' => "Nectar's"]);

    $this->getJson(route('data.venues'))
        ->assertOk()
        ->assertJsonPath('data.0.venuename', "Nectar's");
});

test('shows for a venue are served from the database', function () {
    Http::preventStrayRequests();

    $venue = Venue::factory()->create();
    Show::factory()->create([
        'venueid' => $venue->venueid,
        'showdate' => '1997-11-22',
        'showyear' => 1997,
    ]);

    $this->getJson(route('data.venue-shows', ['venue' => $venue->venueid]))
        ->assertOk()
        ->assertJsonPath('data.0.showdate', '1997-11-22');
});

test('the song catalog is served from the database', function () {
    Http::preventStrayRequests();

    Song::factory()->create(['song' => 'Tweezer', 'slug' => 'tweezer', 'times_played' => 400]);

    $this->getJson(route('data.songs'))
        ->assertOk()
        ->assertJsonPath('data.0.song', 'Tweezer')
        ->assertJsonPath('data.0.times_played', 400);
});

test('show years are derived from stored shows', function () {
    Http::preventStrayRequests();

    Show::factory()->create(['showdate' => '1997-11-22', 'showyear' => 1997]);
    Show::factory()->create(['showdate' => '2003-02-28', 'showyear' => 2003]);
    Show::factory()->create(['showdate' => '2003-07-15', 'showyear' => 2003]);

    $this->getJson(route('data.show-years'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.showyear', '1997')
        ->assertJsonPath('data.1.showyear', '2003');
});

test('the tour explorer page shares the excluded songs config', function () {
    config(['services.phishnet.excluded_songs' => ['jam']]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SongExplorer')
            ->where('excludedSongs', ['jam'])
        );
});

test('the tour explorer page shares the default minimum times played config', function () {
    config(['app.default_min_played' => 25]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SongExplorer')
            ->where('defaultMinPlayed', 25)
        );
});
