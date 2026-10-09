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

test('a picked tour narrows every selected year until it is unpicked', function () {
    $thisYear = (int) date('Y');

    seedTourWithSong($thisYear, 'Current Run', 'Newer Song');
    seedTourWithSong($thisYear - 1, 'Older Run', 'Older Song');

    $page = visit('/');

    $page->click('Played')
        ->assertSee('Newer Song')
        ->assertDontSee('Older Song')
        ->click((string) ($thisYear - 1))
        ->assertSee('Newer Song')
        ->assertDontSee('Older Song')
        ->click("Current Run ({$thisYear})")
        ->assertSee('2 Shows (2 years selected)')
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

test('with no years picked the played status is locked to all and tour plays is hidden', function () {
    $thisYear = (int) date('Y');

    seedTourWithSong($thisYear, 'Current Run', 'Newer Song');
    Song::factory()->create(['song' => 'Never Played Song', 'times_played' => 0]);

    $page = visit('/');

    $page->click('Tour Plays')
        ->click('Not Played')
        ->click((string) $thisYear)
        ->assertSee('Every year')
        ->assertSee('Pick a year to choose')
        ->assertAttribute('[aria-label="Played status"]', 'aria-disabled', 'true')
        ->assertSee('Newer Song')
        ->assertSee('Never Played Song')
        ->assertDontSee('Tour Plays')
        ->click((string) $thisYear)
        ->assertDontSee('Pick a year to choose')
        ->assertSee('Never Played Song')
        ->assertDontSee('Newer Song')
        ->assertNoJavaScriptErrors();
});

test('the filters still apply to search results', function () {
    $thisYear = (int) date('Y');

    seedTourWithSong($thisYear, 'Current Run', 'Searchable Common');
    Song::factory()->create(['song' => 'Searchable Rare', 'times_played' => 1]);

    $page = visit('/');

    $page->click((string) $thisYear)
        ->assertSee('Searchable Rare')
        ->script(<<<'JS'
            const slider = document.getElementById('min-times-played');
            slider.value = '10';
            slider.dispatchEvent(new Event('input', { bubbles: true }));
        JS);

    $page->fill('[aria-label="Search"]', 'Searchable')
        ->assertSee('Searchable Common')
        ->assertDontSee('Searchable Rare')
        ->assertNoJavaScriptErrors();
});

test('the played slider caps the play count from above', function () {
    $thisYear = (int) date('Y');

    seedTourWithSong($thisYear, 'Current Run', 'Tour Song');
    Song::factory()->create(['song' => 'Rarely Played', 'times_played' => 2]);
    Song::factory()->create(['song' => 'Often Played', 'times_played' => 498]);

    $page = visit('/');

    $page->click((string) $thisYear)
        ->click('Not Played')
        ->assertSee('Often Played')
        ->assertAttribute('[aria-label="Most times played"]', 'max', '500')
        ->assertAttribute('[aria-label="Most times played"]', 'step', '5')
        ->script(<<<'JS'
            const slider = document.querySelector('[aria-label="Most times played"]');
            slider.value = '10';
            slider.dispatchEvent(new Event('input', { bubbles: true }));
        JS);

    $page->assertSee('Rarely Played')
        ->assertDontSee('Often Played')
        ->assertSee('0 – 10 times')
        ->assertNoJavaScriptErrors();
});

test('the heading counts shows per year and picked tours', function () {
    $thisYear = (int) date('Y');

    seedTourWithSong($thisYear, 'Spring Run', 'Spring Song', "{$thisYear}-04-01");
    seedTourWithSong($thisYear, 'Summer Run', 'Summer Song', "{$thisYear}-07-01");
    seedTourWithSong($thisYear, 'Fall Run', 'Fall Song', "{$thisYear}-10-01");

    $page = visit('/');

    $page->click('Fall Run')
        ->click('Spring Run')
        ->assertSee('2 Shows (2 tours selected from 1 year)')
        ->assertNoJavaScriptErrors();
});

test('reset filters clears the search box', function () {
    $thisYear = (int) date('Y');

    seedTourWithSong($thisYear, 'Current Run', 'Newer Song');

    $page = visit('/');

    $page->fill('[aria-label="Search"]', 'Newer')
        ->click('Reset filters')
        ->assertValue('[aria-label="Search"]', '')
        ->assertNoJavaScriptErrors();
});
