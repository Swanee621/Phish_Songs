<?php

use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * Nightwatch decides sampling per request, so each test re-arms it before
 * making one; a leftover `false` from an earlier test would otherwise let a
 * broken middleware pass.
 */
beforeEach(function () {
    Nightwatch::sample(rate: 1.0);
});

test('a request from an ignored ip is dropped before it is shipped', function () {
    config(['services.nightwatch.ignored_ips' => '24.236.171.11']);

    $this->withServerVariables(['REMOTE_ADDR' => '24.236.171.11'])
        ->get(route('home'))
        ->assertOk();

    expect(Nightwatch::sampling())->toBeFalse();
});

test('a request from any other ip is still sampled', function () {
    config(['services.nightwatch.ignored_ips' => '24.236.171.11']);

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
        ->get(route('home'))
        ->assertOk();

    expect(Nightwatch::sampling())->toBeTrue();
});

test('every ip in a comma separated list is ignored', function () {
    config(['services.nightwatch.ignored_ips' => '24.236.171.11, 10.0.0.4']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
        ->get(route('home'))
        ->assertOk();

    expect(Nightwatch::sampling())->toBeFalse();
});

test('every request is sampled when no ips are ignored', function () {
    config(['services.nightwatch.ignored_ips' => '']);

    $this->withServerVariables(['REMOTE_ADDR' => '24.236.171.11'])
        ->get(route('home'))
        ->assertOk();

    expect(Nightwatch::sampling())->toBeTrue();
});

test('the json endpoints the page polls are ignored too', function () {
    config(['services.nightwatch.ignored_ips' => '24.236.171.11']);

    $this->withServerVariables(['REMOTE_ADDR' => '24.236.171.11'])
        ->get(route('data.live'))
        ->assertOk();

    expect(Nightwatch::sampling())->toBeFalse();
});
