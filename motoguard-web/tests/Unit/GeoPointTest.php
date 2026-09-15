<?php

use App\Support\GeoPoint;

test('it parses the hex EWKB that PostGIS returns', function () {
    $hex = '0101000020E6100000'.bin2hex(pack('e', 120.9842)).bin2hex(pack('e', 14.5995));

    $point = GeoPoint::fromDatabase(strtoupper($hex));

    expect($point->lat)->toEqualWithDelta(14.5995, 1e-9)
        ->and($point->lng)->toEqualWithDelta(120.9842, 1e-9);
});

test('it round-trips through EWKT', function () {
    $point = new GeoPoint(14.5, 121.25);

    expect(GeoPoint::fromDatabase($point->toEwkt()))->toEqual($point)
        ->and($point->toEwkt())->toBe('SRID=4326;POINT(121.2500000 14.5000000)');
});

test('it builds a Google Maps link', function () {
    expect((new GeoPoint(14.5995, 120.9842))->mapsUrl())->toBe('https://maps.google.com/?q=14.599500,120.984200');
});

test('it rejects impossible coordinates', function () {
    new GeoPoint(91, 0);
})->throws(InvalidArgumentException::class);
