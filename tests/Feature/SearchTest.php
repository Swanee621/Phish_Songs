<?php

use App\Models\SetlistEntry;
use App\Models\Show;
use App\Models\Song;
use App\Models\Venue;

/*
 * Tests run on Scout's collection engine (see phpunit.xml), which matches on
 * substrings of each model's searchable array — enough to pin down what is
 * indexed and how the endpoints shape their answers.
 */

test('the search endpoint finds songs by name and shows by venue', function () {
    Song::factory()->create(['song' => 'Tweezer', 'slug' => 'tweezer', 'times_played' => 400]);
    Song::factory()->create(['song' => 'Harry Hood', 'slug' => 'harry-hood']);

    $venue = Venue::factory()->create(['venuename' => 'Madison Square Garden', 'city' => 'New York', 'state' => 'NY']);
    Show::factory()->for($venue)->create(['showdate' => '2023-12-31', 'showyear' => 2023]);

    $this->getJson(route('data.search', ['q' => 'tweez']))
        ->assertOk()
        ->assertJsonCount(1, 'data.songs')
        ->assertJsonPath('data.songs.0.slug', 'tweezer')
        ->assertJsonPath('data.songs.0.times_played', 400)
        ->assertJsonCount(0, 'data.shows');

    $this->getJson(route('data.search', ['q' => 'madison']))
        ->assertOk()
        ->assertJsonCount(0, 'data.songs')
        ->assertJsonPath('data.shows.0.showdate', '2023-12-31')
        ->assertJsonPath('data.shows.0.venuename', 'Madison Square Garden')
        ->assertJsonPath('data.shows.0.city', 'New York');
});

test('shows are searchable by US-style date', function () {
    $show = Show::factory()->create(['showdate' => '2023-07-04', 'showyear' => 2023]);

    expect($show->toSearchableArray()['us_date'])->toBe('7/4/2023');

    $this->getJson(route('data.search', ['q' => '7/4/2023']))
        ->assertOk()
        ->assertJsonPath('data.shows.0.showdate', '2023-07-04');
});

test('the search endpoint ignores terms shorter than two characters', function () {
    Song::factory()->create(['song' => 'Tweezer']);

    $this->getJson(route('data.search', ['q' => 't']))
        ->assertOk()
        ->assertExactJson(['data' => ['songs' => [], 'shows' => []]]);

    $this->getJson(route('data.search-slugs', ['q' => 't']))
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('search slugs include matching songs and every song played at a matching show', function () {
    Song::factory()->create(['song' => 'Gumbo', 'slug' => 'gumbo']);

    $venue = Venue::factory()->create(['venuename' => 'The Gorge Amphitheatre', 'city' => 'George', 'state' => 'WA']);
    $show = Show::factory()->for($venue)->create();
    $played = Song::factory()->create(['song' => 'Bathtub Gin', 'slug' => 'bathtub-gin']);
    SetlistEntry::factory()->forShow($show, 1)->create(['songid' => $played->songid, 'song' => $played->song, 'slug' => $played->slug]);

    Song::factory()->create(['song' => 'Fluffhead', 'slug' => 'fluffhead']);

    $slugs = $this->getJson(route('data.search-slugs', ['q' => 'gumbo']))
        ->assertOk()
        ->json('data');

    expect($slugs)->toContain('gumbo')->not->toContain('fluffhead');

    $slugs = $this->getJson(route('data.search-slugs', ['q' => 'gorge']))
        ->assertOk()
        ->json('data');

    expect($slugs)->toContain('bathtub-gin')->not->toContain('gumbo', 'fluffhead');
});
