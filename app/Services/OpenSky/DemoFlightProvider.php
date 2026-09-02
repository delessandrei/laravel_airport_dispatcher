<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\OpenSky;

use App\Models\Flight;
use Carbon\CarbonImmutable;

/**
 * Generates a plausible day of traffic when live OpenSky data is unavailable.
 *
 * Output is deterministic: the same airport and date always produce the same
 * flights, so the interface is stable to demo and to screenshot.
 *
 * It returns unsaved Flight models rather than a separate shape, so the flight
 * board renders generated and collected traffic through exactly the same code.
 * Nothing here is ever written to the database.
 */
class DemoFlightProvider
{
    private const NETWORK = [
        'LROP', 'LRCL', 'LRTR', 'EDDF', 'EDDM', 'EDDB', 'EDDL', 'EGLL', 'EGKK',
        'EGCC', 'LFPG', 'EHAM', 'LEMD', 'LEBL', 'LIRF', 'LIMC', 'LOWW', 'LSZH',
        'EBBR', 'EKCH', 'ESSA', 'LTFM', 'EPWA', 'LHBP', 'LKPR',
    ];

    private string $seed = '';

    private int $cursor = 0;

    /**
     * @return array<int, Flight>
     */
    public function arrivals(string $icao, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): array
    {
        return $this->generate($icao, $dayStart, $dayEnd, Flight::DIRECTION_ARRIVAL);
    }

    /**
     * @return array<int, Flight>
     */
    public function departures(string $icao, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): array
    {
        return $this->generate($icao, $dayStart, $dayEnd, Flight::DIRECTION_DEPARTURE);
    }

    /**
     * @return array<int, Flight>
     */
    private function generate(string $icao, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, string $direction): array
    {
        $icao = strtoupper($icao);
        $inbound = $direction === Flight::DIRECTION_ARRIVAL;

        $this->seed = $icao.'|'.$dayStart->toDateString().'|'.$direction;
        $this->cursor = 0;

        $flights = [];
        $count = $this->next(14, 26);
        $prefixes = Airlines::prefixes();

        for ($i = 0; $i < $count; $i++) {
            $callsign = $prefixes[$this->next(0, count($prefixes) - 1)].$this->next(100, 9899);

            $partner = self::NETWORK[$this->next(0, count(self::NETWORK) - 1)];

            if ($partner === $icao) {
                $partner = $partner === 'LROP' ? 'EDDF' : 'LROP';
            }

            // Traffic clusters into morning and evening banks, as it does in reality.
            $minuteOfDay = $this->next(0, 1) === 0
                ? $this->next(5 * 60, 12 * 60)
                : $this->next(13 * 60, 22 * 60 + 30);

            $blockMinutes = $this->next(55, 215);
            $atAirport = $dayStart->addMinutes($minuteOfDay);

            if ($atAirport->greaterThan($dayEnd)) {
                continue;
            }

            $flights[] = new Flight([
                'airport_icao' => $icao,
                'direction' => $direction,
                'day' => $dayStart->toDateString(),
                'icao24' => strtolower(dechex($this->next(0x400000, 0x4fffff))),
                'callsign' => $callsign,
                'departure_airport' => $inbound ? $partner : $icao,
                'arrival_airport' => $inbound ? $icao : $partner,
                'first_seen' => $inbound ? $atAirport->subMinutes($blockMinutes) : $atAirport,
                'last_seen' => $inbound ? $atAirport : $atAirport->addMinutes($blockMinutes),
            ]);
        }

        usort($flights, fn (Flight $a, Flight $b) => $a->boardTime() <=> $b->boardTime());

        return $flights;
    }

    /** Deterministic pseudo-random draw; no global RNG state is touched. */
    private function next(int $min, int $max): int
    {
        $hash = crc32($this->seed.':'.$this->cursor++);

        return $min + ($hash % max(1, $max - $min + 1));
    }
}
