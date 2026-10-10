<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordVisit;
use App\Models\Location;
use App\Models\User;
use App\Models\Visit;
use App\Services\GeoIp\FakeGeoIpResolver;
use App\Services\GeoIp\GeoIpResolver;
use App\Services\GeoIp\GeoLocation;
use Illuminate\Support\Str;

/*
 * The test client always arrives from 127.0.0.1, so a fake resolver with a
 * default answer stands in for the city database.
 */
function placeEveryoneIn(?GeoLocation $place): void
{
    app()->instance(GeoIpResolver::class, new FakeGeoIpResolver(default: $place));
}

function burlington(): GeoLocation
{
    return new GeoLocation(
        latitude: 44.4759,
        longitude: -73.2121,
        city: 'Burlington',
        region: 'Vermont',
        regionCode: 'VT',
        countryCode: 'US',
        country: 'United States',
        geonameId: 5234372,
        accuracyRadius: 20,
    );
}

test('a page view is recorded against its city and the browser is given a visitor id', function () {
    placeEveryoneIn(burlington());

    $this->get('/')
        ->assertOk()
        ->assertPlainCookie(RecordVisit::COOKIE);

    expect(Visit::count())->toBe(1)
        ->and(Location::count())->toBe(1);

    $visit = Visit::first();
    $location = Location::first();

    expect(Str::isUuid($visit->visitor_id))->toBeTrue()
        ->and($visit->location_id)->toBe($location->id)
        ->and($visit->country_code)->toBe('US')
        ->and($visit->path)->toBe('/')
        ->and($location->city)->toBe('Burlington')
        ->and($location->latitude)->toBe(44.4759)
        ->and($location->longitude)->toBe(-73.2121);
});

test('a returning visitor keeps their id and does not duplicate the city', function () {
    placeEveryoneIn(burlington());

    $visitorId = (string) Str::uuid();

    $this->withUnencryptedCookie(RecordVisit::COOKIE, $visitorId)->get('/')->assertOk();
    $this->withUnencryptedCookie(RecordVisit::COOKIE, $visitorId)->get('/setlist-browser')->assertOk();

    expect(Visit::pluck('visitor_id')->unique()->all())->toBe([$visitorId])
        ->and(Visit::pluck('path')->sort()->values()->all())->toBe(['/', '/setlist-browser'])
        ->and(Location::count())->toBe(1);
});

test('a visit whose address cannot be placed is still counted', function () {
    placeEveryoneIn(null);

    $this->get('/')->assertOk();

    expect(Visit::count())->toBe(1)
        ->and(Visit::first()->location_id)->toBeNull()
        ->and(Location::count())->toBe(0);
});

test('the maintainer\'s own addresses are not recorded', function () {
    placeEveryoneIn(burlington());
    config(['services.nightwatch.ignored_ips' => '10.0.0.1, 127.0.0.1']);

    $this->get('/')->assertOk()->assertCookieMissing(RecordVisit::COOKIE);

    expect(Visit::count())->toBe(0);
});

test('nothing but anonymous GET page views counts as a visit', function () {
    placeEveryoneIn(burlington());

    $this->get('/data/songs')->assertOk();
    $this->head('/')->assertOk();
    $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1)')->get('/')->assertOk();
    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'SongChecker',
        'X-Inertia-Partial-Data' => 'excludedSongs',
    ])->get('/')->assertOk();
    $this->actingAs(User::factory()->create())->get('/')->assertOk();
    $this->actingAs(User::factory()->create())->get('/stats')->assertOk();

    expect(Visit::count())->toBe(0);
});
