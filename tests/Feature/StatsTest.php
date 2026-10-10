<?php

use App\Models\Location;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

test('the stats pages are only for someone logged in', function () {
    $this->get('/stats')->assertRedirect('/login');
    $this->getJson('/data/stats/bounds')->assertUnauthorized();
    $this->getJson('/data/stats/heatmap')->assertUnauthorized();
    $this->getJson('/data/stats/travellers')->assertUnauthorized();

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

        $this->traveller = fake()->uuid();
        $this->local = fake()->uuid();

        // The traveller: Burlington twice on the 1st, Denver on the 3rd.
        Visit::factory()->create(['visitor_id' => $this->traveller, 'location_id' => $this->burlington->id, 'visited_at' => CarbonImmutable::parse('2026-10-01 09:00')]);
        Visit::factory()->create(['visitor_id' => $this->traveller, 'location_id' => $this->burlington->id, 'visited_at' => CarbonImmutable::parse('2026-10-01 18:00')]);
        Visit::factory()->create(['visitor_id' => $this->traveller, 'location_id' => $this->denver->id, 'visited_at' => CarbonImmutable::parse('2026-10-03 12:00')]);

        // Someone who only ever appears in Burlington, plus one unplaced visit.
        Visit::factory()->create(['visitor_id' => $this->local, 'location_id' => $this->burlington->id, 'visited_at' => CarbonImmutable::parse('2026-10-02 12:00')]);
        Visit::factory()->unresolved()->create(['visitor_id' => $this->local, 'visited_at' => CarbonImmutable::parse('2026-10-04 12:00')]);
    });

    test('bounds span every visit', function () {
        $this->getJson('/data/stats/bounds')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-10-01')
            ->assertJsonPath('data.to', '2026-10-04')
            ->assertJsonPath('data.visits', 5)
            ->assertJsonPath('data.visitors', 2)
            ->assertJsonPath('data.unresolved', 1);
    });

    test('the heatmap totals visits and visitors per city within the range', function () {
        $this->getJson('/data/stats/heatmap')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.city', 'Burlington')
            ->assertJsonPath('data.0.count', 3)
            ->assertJsonPath('data.0.visitors', 2)
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
            ->assertJsonPath('data.0.count', 2)
            ->assertJsonPath('data.0.visitors', 1);
    });

    test('travellers lists only visitors seen in more than one city, with their stops in order', function () {
        $response = $this->getJson('/data/stats/travellers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.visitor_id', $this->traveller)
            ->assertJsonCount(2, 'data.0.stops')
            ->assertJsonPath('data.0.stops.0.city', 'Burlington')
            ->assertJsonPath('data.0.stops.0.visits', 2)
            ->assertJsonPath('data.0.stops.1.city', 'Denver')
            ->assertJsonPath('data.0.stops.1.visits', 1);

        expect($response->json('data.0.first_seen'))->toStartWith('2026-10-01')
            ->and($response->json('data.0.last_seen'))->toStartWith('2026-10-03');

        // Narrowed to the days they were only in Burlington, they stop being a traveller.
        $this->getJson('/data/stats/travellers?to=2026-10-02')
            ->assertOk()
            ->assertJsonCount(0, 'data');
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
