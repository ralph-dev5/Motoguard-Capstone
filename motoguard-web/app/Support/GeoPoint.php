<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

final readonly class GeoPoint implements JsonSerializable
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new InvalidArgumentException("Invalid coordinates [{$lat}, {$lng}].");
        }
    }

    /**
     * Accepts EWKT ("SRID=4326;POINT(lng lat)") or the hex EWKB that PostGIS returns for geography columns.
     */
    public static function fromDatabase(string $value): self
    {
        if (preg_match('/POINT\s*\(\s*(-?[\d.]+)\s+(-?[\d.]+)\s*\)/i', $value, $matches)) {
            return new self((float) $matches[2], (float) $matches[1]);
        }

        $binary = ctype_xdigit($value) ? hex2bin($value) : false;

        if ($binary === false || strlen($binary) < 21) {
            throw new InvalidArgumentException('Unrecognized geography value.');
        }

        $littleEndian = ord($binary[0]) === 1;
        $header = unpack($littleEndian ? 'Vtype' : 'Ntype', substr($binary, 1, 4));

        if ($header === false || ($header['type'] & 0x0FFFFFFF) !== 1) {
            throw new InvalidArgumentException('Geography value is not a point.');
        }

        $offset = ($header['type'] & 0x20000000) ? 9 : 5;

        if (strlen($binary) < $offset + 16) {
            throw new InvalidArgumentException('Geography point is truncated.');
        }

        $coords = unpack($littleEndian ? 'ex/ey' : 'Ex/Ey', substr($binary, $offset, 16));

        if ($coords === false) {
            throw new InvalidArgumentException('Geography point could not be decoded.');
        }

        return new self($coords['y'], $coords['x']);
    }

    public function toEwkt(): string
    {
        return sprintf('SRID=4326;POINT(%.7F %.7F)', $this->lng, $this->lat);
    }

    public function mapsUrl(): string
    {
        return sprintf('https://maps.google.com/?q=%.6F,%.6F', $this->lat, $this->lng);
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
