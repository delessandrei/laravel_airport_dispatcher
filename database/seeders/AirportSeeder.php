<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace Database\Seeders;

use App\Models\Airport;
use Illuminate\Database\Seeder;

/**
 * Airports for the three proof-of-concept countries: Romania, Germany and
 * the United Kingdom.
 *
 * Terminal and gate layouts are representative rather than authoritative. No
 * free API publishes gate maps, so they are seeded here to give the dispatch
 * allocation step something concrete to assign flights to.
 *
 * Each terminal is declared compactly as [code, name, gatePrefix, from, to]
 * and expanded into individual gate documents by expandTerminals().
 */
class AirportSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->airports() as $airport) {
            $airport['terminals'] = $this->expandTerminals($airport['terminals']);

            Airport::updateOrCreate(['icao' => $airport['icao']], $airport);
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string, 3: int, 4: int}>  $specs
     * @return array<int, array<string, mixed>>
     */
    private function expandTerminals(array $specs): array
    {
        return array_map(function (array $spec): array {
            [$code, $name, $prefix, $from, $to] = $spec;

            $gates = [];

            for ($number = $from; $number <= $to; $number++) {
                $gates[] = [
                    'code' => $prefix.$number,
                    'terminal' => $code,
                    // Remote stands are bus-boarded; the rest have jet bridges.
                    // The allocation algorithm will care about this distinction.
                    'type' => $number % 7 === 0 ? 'remote' : 'jetbridge',
                ];
            }

            return ['code' => $code, 'name' => $name, 'gates' => $gates];
        }, $specs);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function airports(): array
    {
        return [
            // ---------------------------------------------------------- Romania
            $this->ro('LROP', 'OTP', 'Henri Coandă International', 'Bucharest', 44.5722, 26.1022, [
                ['T1', 'Terminal 1 — Schengen', 'A', 1, 18],
                ['T2', 'Terminal 2 — Non-Schengen', 'B', 1, 16],
            ]),
            $this->ro('LRBS', 'BBU', 'Băneasa — Aurel Vlaicu', 'Bucharest', 44.5032, 26.1021, [
                ['T1', 'Main Terminal', 'A', 1, 6],
            ]),
            $this->ro('LRCL', 'CLJ', 'Avram Iancu International', 'Cluj-Napoca', 46.7852, 23.6862, [
                ['T1', 'Departures Terminal', 'A', 1, 10],
                ['T2', 'Arrivals Terminal', 'B', 1, 1],
            ]),
            $this->ro('LRTR', 'TSR', 'Traian Vuia International', 'Timișoara', 45.8099, 21.3379, [
                ['T1', 'Main Terminal', 'A', 1, 9],
            ]),
            $this->ro('LRIA', 'IAS', 'Iași International', 'Iași', 47.1785, 27.6206, [
                ['T1', 'Terminal 1', 'A', 1, 6],
                ['T2', 'Terminal 2', 'B', 1, 5],
            ]),

            // ---------------------------------------------------------- Germany
            $this->de('EDDF', 'FRA', 'Frankfurt am Main', 'Frankfurt', 50.0379, 8.5622, [
                ['T1', 'Terminal 1 — Piers A/B/C', 'A', 1, 30],
                ['T2', 'Terminal 2 — Piers D/E', 'D', 1, 24],
                ['T3', 'Terminal 3', 'G', 1, 20],
            ]),
            $this->de('EDDM', 'MUC', 'Munich Franz Josef Strauss', 'Munich', 48.3538, 11.7861, [
                ['T1', 'Terminal 1', 'A', 1, 22],
                ['T2', 'Terminal 2', 'G', 1, 28],
            ]),
            $this->de('EDDB', 'BER', 'Berlin Brandenburg', 'Berlin', 52.3667, 13.5033, [
                ['T1', 'Terminal 1', 'A', 1, 26],
                ['T2', 'Terminal 2', 'B', 1, 12],
            ]),
            $this->de('EDDL', 'DUS', 'Düsseldorf', 'Düsseldorf', 51.2895, 6.7668, [
                ['TA', 'Terminal A', 'A', 1, 14],
                ['TB', 'Terminal B', 'B', 1, 12],
                ['TC', 'Terminal C', 'C', 1, 10],
            ]),
            $this->de('EDDH', 'HAM', 'Hamburg', 'Hamburg', 53.6304, 9.9882, [
                ['T1', 'Terminal 1', 'A', 1, 12],
                ['T2', 'Terminal 2', 'B', 1, 14],
            ]),
            $this->de('EDDK', 'CGN', 'Cologne Bonn', 'Cologne', 50.8659, 7.1427, [
                ['T1', 'Terminal 1', 'A', 1, 14],
                ['T2', 'Terminal 2', 'B', 1, 10],
            ]),
            $this->de('EDDS', 'STR', 'Stuttgart', 'Stuttgart', 48.6899, 9.2220, [
                ['T1', 'Terminal 1', 'A', 1, 12],
                ['T3', 'Terminal 3', 'C', 1, 8],
            ]),
            $this->de('EDDV', 'HAJ', 'Hannover', 'Hannover', 52.4611, 9.6851, [
                ['TA', 'Terminal A', 'A', 1, 8],
                ['TB', 'Terminal B', 'B', 1, 8],
            ]),
            $this->de('EDDN', 'NUE', 'Nuremberg', 'Nuremberg', 49.4987, 11.0780, [
                ['T1', 'Main Terminal', 'A', 1, 10],
            ]),
            $this->de('EDDP', 'LEJ', 'Leipzig/Halle', 'Leipzig', 51.4239, 12.2364, [
                ['TA', 'Terminal A', 'A', 1, 8],
                ['TB', 'Terminal B', 'B', 1, 6],
            ]),
            $this->de('EDDW', 'BRE', 'Bremen', 'Bremen', 53.0475, 8.7867, [
                ['T1', 'Main Terminal', 'A', 1, 8],
            ]),
            $this->de('EDDC', 'DRS', 'Dresden', 'Dresden', 51.1328, 13.7672, [
                ['T1', 'Main Terminal', 'A', 1, 6],
            ]),
            $this->de('EDDG', 'FMO', 'Münster Osnabrück', 'Münster', 52.1346, 7.6848, [
                ['T1', 'Main Terminal', 'A', 1, 6],
            ]),
            $this->de('EDDR', 'SCN', 'Saarbrücken', 'Saarbrücken', 49.2146, 7.1095, [
                ['T1', 'Main Terminal', 'A', 1, 4],
            ]),

            // --------------------------------------------------- United Kingdom
            $this->gb('EGLL', 'LHR', 'London Heathrow', 'London', 51.4700, -0.4543, [
                ['T2', 'Terminal 2 — The Queen’s Terminal', 'A', 1, 24],
                ['T3', 'Terminal 3', 'B', 32, 49],
                ['T4', 'Terminal 4', 'D', 1, 12],
                ['T5', 'Terminal 5', 'C', 1, 30],
            ]),
            $this->gb('EGKK', 'LGW', 'London Gatwick', 'London', 51.1537, -0.1821, [
                ['TN', 'North Terminal', 'A', 1, 26],
                ['TS', 'South Terminal', 'B', 1, 24],
            ]),
            $this->gb('EGSS', 'STN', 'London Stansted', 'London', 51.8850, 0.2350, [
                ['T1', 'Main Terminal', 'A', 1, 20],
            ]),
            $this->gb('EGGW', 'LTN', 'London Luton', 'London', 51.8747, -0.3683, [
                ['T1', 'Main Terminal', 'A', 1, 16],
            ]),
            $this->gb('EGLC', 'LCY', 'London City', 'London', 51.5053, 0.0553, [
                ['T1', 'Main Terminal', 'A', 1, 12],
            ]),
            $this->gb('EGCC', 'MAN', 'Manchester', 'Manchester', 53.3537, -2.2750, [
                ['T1', 'Terminal 1', 'A', 1, 18],
                ['T2', 'Terminal 2', 'B', 1, 16],
                ['T3', 'Terminal 3', 'C', 1, 10],
            ]),
            $this->gb('EGPH', 'EDI', 'Edinburgh', 'Edinburgh', 55.9500, -3.3725, [
                ['T1', 'Main Terminal', 'A', 1, 14],
            ]),
            $this->gb('EGPF', 'GLA', 'Glasgow', 'Glasgow', 55.8719, -4.4331, [
                ['T1', 'Main Terminal', 'A', 1, 12],
            ]),
            $this->gb('EGBB', 'BHX', 'Birmingham', 'Birmingham', 52.4539, -1.7480, [
                ['T1', 'Main Terminal', 'A', 1, 14],
            ]),
            $this->gb('EGGD', 'BRS', 'Bristol', 'Bristol', 51.3827, -2.7191, [
                ['T1', 'Main Terminal', 'A', 1, 10],
            ]),
            $this->gb('EGNT', 'NCL', 'Newcastle', 'Newcastle', 55.0375, -1.6917, [
                ['T1', 'Main Terminal', 'A', 1, 10],
            ]),
            $this->gb('EGPD', 'ABZ', 'Aberdeen', 'Aberdeen', 57.2019, -2.1978, [
                ['T1', 'Main Terminal', 'A', 1, 8],
            ]),
            $this->gb('EGHI', 'SOU', 'Southampton', 'Southampton', 50.9503, -1.3568, [
                ['T1', 'Main Terminal', 'A', 1, 6],
            ]),
            $this->gb('EGNX', 'EMA', 'East Midlands', 'Derby', 52.8311, -1.3281, [
                ['T1', 'Main Terminal', 'A', 1, 8],
            ]),
            $this->gb('EGFF', 'CWL', 'Cardiff', 'Cardiff', 51.3967, -3.3433, [
                ['T1', 'Main Terminal', 'A', 1, 6],
            ]),
            $this->gb('EGAA', 'BFS', 'Belfast International', 'Belfast', 54.6575, -6.2158, [
                ['T1', 'Main Terminal', 'A', 1, 8],
            ]),
        ];
    }

    private function ro(string $icao, string $iata, string $name, string $city, float $lat, float $lon, array $terminals): array
    {
        return $this->airport($icao, $iata, $name, $city, 'RO', 'Romania', 'Europe/Bucharest', $lat, $lon, $terminals);
    }

    private function de(string $icao, string $iata, string $name, string $city, float $lat, float $lon, array $terminals): array
    {
        return $this->airport($icao, $iata, $name, $city, 'DE', 'Germany', 'Europe/Berlin', $lat, $lon, $terminals);
    }

    private function gb(string $icao, string $iata, string $name, string $city, float $lat, float $lon, array $terminals): array
    {
        return $this->airport($icao, $iata, $name, $city, 'GB', 'United Kingdom', 'Europe/London', $lat, $lon, $terminals);
    }

    private function airport(string $icao, string $iata, string $name, string $city, string $countryCode, string $countryName, string $timezone, float $lat, float $lon, array $terminals): array
    {
        return [
            'icao' => $icao,
            'iata' => $iata,
            'name' => $name,
            'city' => $city,
            'country_code' => $countryCode,
            'country_name' => $countryName,
            'timezone' => $timezone,
            'latitude' => $lat,
            'longitude' => $lon,
            'terminals' => $terminals,
        ];
    }
}
