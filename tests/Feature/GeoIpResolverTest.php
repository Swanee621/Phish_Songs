<?php

use App\Services\GeoIp\MaxMindResolver;

test('a missing database resolves nothing rather than failing the request', function () {
    $resolver = new MaxMindResolver(storage_path('app/geoip/does-not-exist.mmdb'));

    expect($resolver->resolve('8.8.8.8'))->toBeNull();
});

test('private and loopback addresses are never looked up', function (string $ip) {
    $resolver = new MaxMindResolver(__FILE__);

    expect($resolver->resolve($ip))->toBeNull();
})->with(['127.0.0.1', '10.1.2.3', '192.168.0.10', '::1']);
