<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
 * A stand-in for MaxMind's archive: one dated folder holding a file that
 * carries the MMDB metadata marker and edition name the command checks for.
 */
function fakeGeoLiteArchive(string $contents): string
{
    $dir = sys_get_temp_dir().'/geoip-test-'.uniqid();
    File::ensureDirectoryExists($dir);

    $tar = new PharData($dir.'/GeoLite2-City.tar');
    $tar->addFromString('GeoLite2-City_20261007/GeoLite2-City.mmdb', $contents);
    $tar->addFromString('GeoLite2-City_20261007/LICENSE.txt', 'CC BY-SA');
    $tar->compress(Phar::GZ);

    return (string) file_get_contents($dir.'/GeoLite2-City.tar.gz');
}

beforeEach(function () {
    $this->destination = sys_get_temp_dir().'/geoip-dest-'.uniqid().'/GeoLite2-City.mmdb';

    config([
        'services.maxmind.account_id' => '123456',
        'services.maxmind.license_key' => 'secret-key',
        'services.maxmind.database_path' => $this->destination,
    ]);
});

afterEach(function () {
    File::deleteDirectory(dirname($this->destination));
});

test('geoip:update installs the database from the authenticated download', function () {
    $mmdb = str_repeat('x', 64)."\xab\xcd\xefMaxMind.com\x00database_type\x0dGeoLite2-City";

    Http::fake(['download.maxmind.com/*' => Http::response(fakeGeoLiteArchive($mmdb))]);

    $this->artisan('geoip:update')->assertSuccessful();

    expect(file_get_contents($this->destination))->toBe($mmdb)
        ->and(is_dir(dirname($this->destination).'/tmp'))->toBeFalse();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'GeoLite2-City/download')
        && str_contains($request->url(), 'suffix=tar.gz')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('123456:secret-key')));
});

test('geoip:update leaves the existing database alone when the download is not a city database', function () {
    File::ensureDirectoryExists(dirname($this->destination));
    file_put_contents($this->destination, 'the one we had');

    Http::fake(['download.maxmind.com/*' => Http::response(fakeGeoLiteArchive('not an mmdb at all'))]);

    $this->artisan('geoip:update')->assertFailed();

    expect(file_get_contents($this->destination))->toBe('the one we had');
});

test('geoip:update fails without credentials and never calls out', function () {
    config(['services.maxmind.license_key' => '']);
    Http::fake();

    $this->artisan('geoip:update')->assertFailed();

    Http::assertNothingSent();
    expect(file_exists($this->destination))->toBeFalse();
});
