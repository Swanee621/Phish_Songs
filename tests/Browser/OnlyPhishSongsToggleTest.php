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

test('the only phish songs checkbox is offered on every song checker tab', function (string $tab) {
    $show = Show::factory()->forYear((int) date('Y'))->create();
    SetlistEntry::factory()->forShow($show, 1)->create();
    Song::factory()->count(3)->create();

    $page = visit('/');

    $page->click($tab)
        ->assertSee('Only Phish Songs')
        ->assertNoJavaScriptErrors();
})->with(['All', 'Played', 'Not Played']);
