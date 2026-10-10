<?php

use App\Models\Location;
use App\Models\User;
use App\Models\Visit;
use App\Services\Stats\VisitStats;
use Database\Seeders\VisitorStatsSeeder;

test('the sample traffic gives every part of the stats page something to show', function () {
    $this->seed(VisitorStatsSeeder::class);

    $stats = app(VisitStats::class);

    expect(User::where('email', 'test@example.com')->exists())->toBeTrue()
        ->and(Location::count())->toBe(13)
        ->and(Visit::count())->toBeGreaterThan(100)
        ->and(Visit::whereNull('location_id')->count())->toBe(6)
        ->and($stats->heatmap(null, null))->toHaveCount(13)
        ->and($stats->travellers(null, null))->toHaveCount(4);
});

test('the sample traffic can be seeded twice without duplicating cities or the login', function () {
    $this->seed(VisitorStatsSeeder::class);
    $this->seed(VisitorStatsSeeder::class);

    expect(User::count())->toBe(1)
        ->and(Location::count())->toBe(13);
});
