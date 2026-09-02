<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * @property string $icao  Four-letter ICAO code, the identifier OpenSky expects (e.g. LROP).
 * @property string $iata  Three-letter IATA code, the one passengers see (e.g. OTP).
 */
class Airport extends Model
{
    protected $connection = 'mongodb';

    protected string $collection = 'airports';

    protected $fillable = [
        'icao', 'iata', 'name', 'city', 'country_code', 'country_name',
        'latitude', 'longitude', 'timezone', 'terminals',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float'
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'icao';
    }

    /**
     * Every gate of the airport, flattened out of its terminals.
     *
     * Gates are nested inside terminals, which is the whole point of using a
     * document store: one read returns the airport and its full layout, so this
     * costs no query. Each gate carries the terminal it belongs to, because
     * everything downstream needs both.
     *
     * @return array<int, array<string, string>>
     */
    public function gates(): array
    {
        $gates = [];

        foreach ($this->terminals ?? [] as $terminal) {
            foreach ($terminal['gates'] ?? [] as $gate) {
                $gates[] = [
                    'code' => $gate['code'],
                    'terminal' => $terminal['code'],
                    'type' => $gate['type'] ?? 'jetbridge',
                ];
            }
        }

        return $gates;
    }

    public function gateCount(): int
    {
        return count($this->gates());
    }

    public function terminalCount(): int
    {
        return count($this->terminals ?? []);
    }

    public function scopeInCountry($query, string $countryCode)
    {
        return $query->where('country_code', strtoupper($countryCode));
    }
}
