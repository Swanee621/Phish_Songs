<?php

use App\Models\SetlistEntry;
use App\Models\Show;
use App\Models\Song;

/*
 * Skipped without the browser plugin, the same as ScrollMemoryTest — it needs
 * PHP's sockets extension enabled before it will install.
 */
beforeEach(function () {
    if (! is_dir(base_path('vendor/pestphp/pest-plugin-browser'))) {
        $this->markTestSkipped(
            'Requires pestphp/pest-plugin-browser (enable PHP ext-sockets, then composer require pestphp/pest-plugin-browser:^4.3 --dev).'
        );
    }
});

test('the phish / covers / both control is offered on every song checker tab', function (string $tab) {
    $show = Show::factory()->forYear((int) date('Y'))->create();
    SetlistEntry::factory()->forShow($show, 1)->create();
    Song::factory()->count(3)->create();

    $page = visit('/');

    $page->click($tab)
        ->assertSee('Phish Songs')
        ->assertSee('Covers')
        ->assertSee('Both')
        ->assertNoJavaScriptErrors();
})->with(['All', 'Played', 'Not Played']);

test('the covers option keeps only songs by other artists', function () {
    $show = Show::factory()->forYear((int) date('Y'))->create();
    SetlistEntry::factory()->forShow($show, 1)->create();
    Song::factory()->create(['song' => 'Original Tune', 'artist' => 'Phish', 'times_played' => 50]);
    Song::factory()->create(['song' => 'Borrowed Tune', 'artist' => 'Talking Heads', 'times_played' => 50]);

    $page = visit('/');

    $page->click('All')
        ->click('Covers')
        ->assertSee('1 cover song')
        ->assertSee('Borrowed Tune')
        ->assertDontSee('Original Tune')
        ->click('Phish Songs')
        ->assertSee('Phish songs')
        ->assertSee('Original Tune')
        ->assertDontSee('Borrowed Tune')
        ->assertNoJavaScriptErrors();
});

test('the phish songs option still filters covers out of search results', function () {
    $show = Show::factory()->forYear((int) date('Y'))->create();
    SetlistEntry::factory()->forShow($show, 1)->create();
    Song::factory()->create(['song' => 'Rolling Original', 'artist' => 'Phish', 'times_played' => 50]);
    Song::factory()->create(['song' => 'Rolling Cover', 'artist' => 'The Rolling Stones', 'times_played' => 50]);

    $page = visit('/');

    // Clicking the control blurs the search box, closing its dropdown, so
    // only the song grid is left to assert against.
    $page->click('All')
        ->type('input[aria-label="Search"]', 'rolling')
        ->click('Phish Songs')
        ->wait(0.5)
        ->assertSee('Rolling Original')
        ->assertDontSee('Rolling Cover')
        ->assertNoJavaScriptErrors();
});
