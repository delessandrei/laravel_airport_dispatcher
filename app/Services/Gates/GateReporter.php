<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\Gates;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\GateClosure;
use Carbon\CarbonImmutable;

/**
 * Hourly statistics: what moved, how the gates were used, and what could not be
 * placed.
 *
 * Movement counts use the times the aircraft actually moved rather than gate
 * occupancy, because "how many departed" is a question about aircraft, not
 * about stands.
 */
class GateReporter
{
    private const TOP_GATES = 5;

    /**
     * @return array<string, mixed>
     */
    public function report(Airport $airport, CarbonImmutable $at): array
    {
        $timezone = $airport->timezone ?: 'UTC';
        $local = $at->setTimezone($timezone);

        return [
            'last_hour' => $this->movements($airport, $at->subHour(), $at),
            'day_to_date' => $this->movements($airport, $local->startOfDay()->utc(), $at),
            'gates' => $this->gates($airport, $at, $local),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movements(Airport $airport, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $icao = strtoupper($airport->icao);

        $departures = Flight::where('airport_icao', $icao)
            ->where('direction', Flight::DIRECTION_DEPARTURE)
            ->whereBetween('first_seen', [$from, $until])->count();

        $arrivals = Flight::where('airport_icao', $icao)
            ->where('direction', Flight::DIRECTION_ARRIVAL)
            ->whereBetween('last_seen', [$from, $until])->count();

        $allocated = Flight::where('airport_icao', $icao)
            ->whereBetween('occupies_from', [$from, $until])
            ->whereNotNull('gate_code')->count();

        $unallocated = Flight::where('airport_icao', $icao)
            ->whereBetween('occupies_from', [$from, $until])
            ->where('allocation_status', Flight::UNALLOCATED)->get();

        return [
            'from' => $from,
            'until' => $until,
            'departures' => $departures,
            'arrivals' => $arrivals,
            'allocated' => $allocated,
            'unallocated' => $unallocated->count(),
            'unallocated_reasons' => $unallocated->countBy('allocation_reason')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gates(Airport $airport, CarbonImmutable $at, CarbonImmutable $local): array
    {
        $icao = strtoupper($airport->icao);
        $all = collect($airport->gates())->pluck('code');

        $closed = GateClosure::forAirport($icao)->touching($at, $at->addSecond())->pluck('gate_code')->unique();

        $inUse = Flight::where('airport_icao', $icao)
            ->whereNotNull('gate_code')
            ->where('occupies_from', '<=', $at)
            ->where('occupies_until', '>', $at)
            ->pluck('gate_code')->unique();

        $usedToday = Flight::where('airport_icao', $icao)
            ->whereNotNull('gate_code')
            ->whereBetween('occupies_from', [$local->startOfDay()->utc(), $local->endOfDay()->utc()])
            ->get(['gate_code']);

        $top = $usedToday->countBy('gate_code')->sortDesc()->take(self::TOP_GATES)
            ->map(fn (int $n, string $gate) => ['gate' => $gate, 'flights' => $n])->values()->all();

        return [
            'total' => $all->count(),
            'closed_now' => $closed->count(),
            'in_use_now' => $inUse->count(),
            // A gate that is both closed and occupied must not be counted twice.
            'free_now' => $all->reject(fn (string $g) => $closed->contains($g) || $inUse->contains($g))->count(),
            'distinct_used_today' => $usedToday->pluck('gate_code')->unique()->count(),
            'top_gates' => $top,
        ];
    }
}
