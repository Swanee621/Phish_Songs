<?php

use App\Models\Location;
use App\Models\User;
use App\Models\Visit;
use App\Services\Stats\VisitStats;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

test('the stats pages are only for someone logged in', function () {
    $this->get('/stats')->assertRedirect('/login');
    $this->getJson('/data/stats/bounds')->assertUnauthorized();
    $this->getJson('/data/stats/heatmap')->assertUnauthorized();
    $this->getJson('/data/stats/visits')->assertUnauthorized();

    $this->actingAs(User::factory()->create())
        ->get('/stats')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Stats'));
});

describe('aggregates', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());

        $this->burlington = Location::factory()->create(['city' => 'Burlington', 'latitude' => 44.4759, 'longitude' => -73.2121]);
        $this->denver = Location::factory()->create(['city' => 'Denver', 'latitude' => 39.7392, 'longitude' => -104.9903]);

        // Burlington on the 1st (twice) and 2nd, Denver on the 3rd, one unplaced visit on the 4th.
        Visit::factory()->create(['location_id' => $this->burlington->id, 'path' => '/', 'visited_at' => CarbonImmutable::parse('2026-10-01 09:00')]);
        Visit::factory()->create(['location_id' => $this->burlington->id, 'path' => '/setlist-browser', 'visited_at' => CarbonImmutable::parse('2026-10-01 18:00')]);
        Visit::factory()->create(['location_id' => $this->burlington->id, 'visited_at' => CarbonImmutable::parse('2026-10-02 12:00')]);
        Visit::factory()->create(['location_id' => $this->denver->id, 'visited_at' => CarbonImmutable::parse('2026-10-03 12:00')]);
        Visit::factory()->unresolved()->create(['visited_at' => CarbonImmutable::parse('2026-10-04 12:00')]);
    });

    test('bounds span every visit', function () {
        $this->getJson('/data/stats/bounds')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-10-01')
            ->assertJsonPath('data.to', '2026-10-04')
            ->assertJsonPath('data.visits', 5)
            ->assertJsonPath('data.unresolved', 1)
            ->assertJsonMissingPath('data.visitors');
    });

    test('the heatmap totals visits per city within the range', function () {
        $this->getJson('/data/stats/heatmap')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.city', 'Burlington')
            ->assertJsonPath('data.0.count', 3)
            ->assertJsonPath('data.0.lat', 44.4759)
            ->assertJsonPath('data.1.city', 'Denver')
            ->assertJsonPath('data.1.count', 1);

        $this->getJson('/data/stats/heatmap?from=2026-10-02&to=2026-10-03')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.count', 1)
            ->assertJsonPath('data.1.count', 1);

        $this->getJson('/data/stats/heatmap?to=2026-10-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.city', 'Burlington')
            ->assertJsonPath('data.0.count', 2);
    });

    test('the visit list has every visit in the range, newest first, unplaced ones included', function () {
        $this->getJson('/data/stats/visits')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.city', null)
            ->assertJsonPath('data.0.lat', null)
            ->assertJsonPath('data.1.city', 'Denver')
            ->assertJsonPath('data.1.lat', 39.7392)
            ->assertJsonPath('data.4.city', 'Burlington')
            ->assertJsonPath('data.4.path', '/')
            ->assertJsonPath('data.4.visited_at', '2026-10-01T09:00:00+00:00')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.lastPage', 1)
            ->assertJsonMissingPath('data.0.visitor_id');

        $this->getJson('/data/stats/visits?from=2026-10-02&to=2026-10-03')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.city', 'Denver')
            ->assertJsonPath('data.1.city', 'Burlington')
            ->assertJsonPath('meta.total', 2);
    });

    test('the visit list is paged', function () {
        Visit::factory()->count(VisitStats::VISITS_PER_PAGE)->create([
            'location_id' => $this->denver->id,
            'visited_at' => CarbonImmutable::parse('2026-10-05 12:00'),
        ]);

        $this->getJson('/data/stats/visits')
            ->assertOk()
            ->assertJsonCount(VisitStats::VISITS_PER_PAGE, 'data')
            ->assertJsonPath('data.0.city', 'Denver')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.perPage', VisitStats::VISITS_PER_PAGE)
            ->assertJsonPath('meta.total', VisitStats::VISITS_PER_PAGE + 5)
            ->assertJsonPath('meta.lastPage', 2);

        $this->getJson('/data/stats/visits?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.city', null)
            ->assertJsonPath('meta.page', 2);

        $this->getJson('/data/stats/visits?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');
    });

    test('a malformed range is rejected', function () {
        $this->getJson('/data/stats/heatmap?from=yesterday')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');

        $this->getJson('/data/stats/heatmap?from=2026-10-03&to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    });
});
