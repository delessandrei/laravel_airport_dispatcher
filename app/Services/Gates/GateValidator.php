<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\Gates;

use App\Models\Airport;
use App\Models\AllocationIssue;
use App\Models\Flight;
use Carbon\CarbonImmutable;

/**
 * Re-checks allocations that are current, and reports the ones that stopped
 * being correct.
 *
 * Three things can go wrong after a correct allocation, and nothing else in the
 * system would notice:
 *
 *   double_booked     two allocations overlap on one gate — the allocator has a
 *                     bug, or two passes raced each other
 *   closed_gate       a closure appeared outside gates:close, which would have
 *                     relocated the flights standing there
 *   malformed_window  the occupancy no longer matches the configured duration,
 *                     usually because GATE_OCCUPANCY_MINUTES changed
 *
 * Only a window around now is examined. Finished occupancies are settled — no
 * amount of checking changes what already happened.
 */
class GateValidator
{
    public function __construct(private readonly GateAllocationService $allocation) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function validate(Airport $airport, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $flights = Flight::overlapping($airport->icao, $from, $until)->get();

        $closures = $this->allocation->closuresFor($airport, $from, $until);
        $expected = (int) config('dispatch.gate.occupancy_minutes');

        $issues = [];

        foreach ($flights as $flight) {
            $issues = array_merge($issues,
                $this->checkWindow($flight, $expected),
                $this->checkClosures($flight, $closures),
            );
        }

        return array_merge($issues, $this->checkDoubleBookings($flights));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkWindow(Flight $flight, int $expected): array
    {
        $from = $flight->occupies_from;
        $until = $flight->occupies_until;

        if ($from === null || $until === null) {
            return [$this->issue(AllocationIssue::MALFORMED_WINDOW, $flight, 'Allocated but has no occupancy window.')];
        }

        if ($from->greaterThanOrEqualTo($until)) {
            return [$this->issue(AllocationIssue::MALFORMED_WINDOW, $flight, 'Window ends before it starts.')];
        }

        $minutes = (int) round($from->diffInMinutes($until));

        return $minutes === $expected
            ? []
            : [$this->issue(AllocationIssue::MALFORMED_WINDOW, $flight, "Window is {$minutes} min, expected {$expected}.")];
    }

    /**
     * @param  array<int, array<string, mixed>>  $closures
     * @return array<int, array<string, mixed>>
     */
    private function checkClosures(Flight $flight, array $closures): array
    {
        if ($flight->occupies_from === null || $flight->occupies_until === null) {
            return [];
        }

        $from = CarbonImmutable::instance($flight->occupies_from);
        $until = CarbonImmutable::instance($flight->occupies_until);

        foreach ($closures as $closure) {
            // The same predicate the allocator uses, so the two cannot disagree.
            if (GateAllocator::closureBlocks($closure, (string) $flight->gate_code, $from, $until)) {
                $reason = (string) ($closure['reason'] ?? '');

                return [$this->issue(AllocationIssue::CLOSED_GATE, $flight,
                    'Gate is closed over this window'.($reason !== '' ? ": {$reason}." : '.'))];
            }
        }

        return [];
    }

    /**
     * Two allocations on one gate whose windows overlap. This should be
     * impossible; if it appears, something bypassed the allocator.
     *
     * @param  \Illuminate\Support\Collection<int, Flight>  $flights
     * @return array<int, array<string, mixed>>
     */
    private function checkDoubleBookings($flights): array
    {
        $issues = [];

        foreach ($flights->groupBy('gate_code') as $gate => $onGate) {
            $sorted = $onGate->filter(fn (Flight $f) => $f->occupies_from && $f->occupies_until)
                ->sortBy(fn (Flight $f) => $f->occupies_from->getTimestamp())
                ->values();

            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];

                if ($current->occupies_from->lessThan($previous->occupies_until)) {
                    $issues[] = $this->issue(AllocationIssue::DOUBLE_BOOKED, $current,
                        "Overlaps {$previous->callsign} on gate {$gate}.");
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $type, Flight $flight, string $detail): array
    {
        return [
            'type' => $type,
            'flight_id' => (string) $flight->getKey(),
            'callsign' => $flight->callsign,
            'gate_code' => $flight->gate_code,
            'detail' => $detail,
        ];
    }
}
