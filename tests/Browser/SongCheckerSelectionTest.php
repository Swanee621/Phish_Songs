<?php

use App\Models\SetlistEntry;
use App\Models\Show;
use App\Models\Song;
use App\Models\Tour;

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

/**
 * One show on its own named tour (or an existing one), with a single song
 * played at it.
 */
function seedTourWithSong(int $year, string|Tour $tour, string $songName, ?string $showdate = null): void
{
    if (is_string($tour)) {
        $tour = Tour::factory()->create(['tourname' => $tour, 'tourwhen' => $tour]);
    }

    $show = Show::factory()->forYear($year)->create(array_filter([
        'tourid' => $tour->tourid,
        'showdate' => $showdate,
    ]));
    $song = Song::factory()->create(['song' => $songName, 'times_played' => 50]);

    SetlistEntry::factory()->forShow($show, 1)->create([
        'songid' => $song->songid,
        'song' => $song->song,
        'slug' => $song->slug,
    ]);
}

test('a second year widens the played list to both years', function () {
    $thisYear = (int) date('Y');

    seedTourWithSong($thisYear, 'Current Run', 'Newer Song');
    seedTourWithSong($thisYear - 1, 'Older Run', 'Older Song');

    $page = visit('/');

    $page->click('Played')
        ->assertSee('Newer Song')
        ->assertDontSee('Older Song')
        ->click((string) ($thisYear - 1))
        ->assertSee('2 shows')
        ->assertSee('2 songs played')
        ->assertDontSee('Previous tour')
        ->assertSee('Newer Song')
        ->assertSee('Older Song')
        ->assertNoJavaScriptErrors();
});

/*
 * phish.net files every one-off show in every year under the same "Not Part of
 * a Tour" id, so picking it in one year must not pick it in the others.
 */
test('picking a tour shared across years only picks it in that year', function () {
    $thisYear = (int) date('Y');
    $lastYear = $thisYear - 1;
    $notPartOfATour = Tour::factory()->create(['tourname' => 'Not Part of a Tour', 'tourwhen' => 'Various']);

    seedTourWithSong($thisYear, 'Current Run', 'Current Run Song', "{$thisYear}-08-01");
    seedTourWithSong($thisYear, $notPartOfATour, 'This Year One-Off', "{$thisYear}-01-15");
    seedTourWithSong($lastYear, $notPartOfATour, 'Last Year One-Off', "{$lastYear}-01-15");

    $page = visit('/');

    $page->click('Played')
        ->click((string) $lastYear)
        ->click("Not Part of a Tour ({$lastYear})")
        ->assertSee('Current Run Song')
        ->assertSee('Last Year One-Off')
        ->assertDontSee('This Year One-Off')
        ->assertNoJavaScriptErrors();
});

test('deselecting every year shows the whole catalog', function () {
    $thisYear = (int) date('Y');

    seedTourWithSong($thisYear, 'Current Run', 'Newer Song');
    Song::factory()->create(['song' => 'Catalog Only Song', 'times_played' => 50]);

    $page = visit('/');

    $page->click('Played')
        ->assertDontSee('Catalog Only Song')
        ->click((string) $thisYear)
        ->assertSee('Every year')
        ->assertSee('Catalog Only Song')
        ->assertNoJavaScriptErrors();
});

test('reset filters returns to the most recent tour', function () {
    $thisYear = (int) date('Y');

    seedTourWithSong($thisYear, 'Current Run', 'Newer Song');

    $page = visit('/');

    $page->click((string) $thisYear)
        ->assertSee('Every year')
        ->click('Reset filters')
        ->assertDontSee('Every year')
        ->assertSee('Current Run')
        ->assertSee('Newer Song')
        ->assertNoJavaScriptErrors();
});
