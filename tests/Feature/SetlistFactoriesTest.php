<?php

use App\Models\SetlistEntry;
use App\Models\Show;

/*
 * The browser tests for the scroll memory seed their pages through these
 * factories, so a break here would surface there as an unexplained empty page.
 */

test('the setlist factories produce a show the data endpoint returns', function () {
    $show = Show::factory()->forYear((int) date('Y'))->create();

    foreach (range(1, 3) as $position) {
        SetlistEntry::factory()->forShow($show, $position)->create();
    }

    $rows = $this->getJson('/data/recent-setlists')
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('showid')->unique()->all())->toBe([$show->showid])
        ->and(collect($rows)->pluck('position')->all())->toBe([1, 2, 3]);
});
