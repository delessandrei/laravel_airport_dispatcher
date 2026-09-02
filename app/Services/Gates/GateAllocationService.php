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
use Illuminate\Support\Collection;

/**
 * Everything the pure allocator refuses to know about: where gates, closures
 * and flights live, and how an occupancy window is derived from a flight.
 *
 * Keeping this apart is what lets GateAllocator be tested with plain arrays.
 */
class GateAllocationService
{
    public function __construct(private readonly GateAllocator $allocator) {}

    /**
     * Places every flight of this airport that has no gate yet.
     *
     * @return array{allocations: array<int, array<string, mixed>>, unallocated: array<string, string>}
     */
    public function allocatePending(Airport $airport): array
    {
        return $this->allocate($airport, Flight::needingAllocation($airport->icao)->get());
    }

    /**
     * @param  Collection<int, Flight>  $flights
     * @return array{allocations: array<int, array<string, mixed>>, unallocated: array<string, string>}
     */
    public function allocate(Airport $airport, Collection $flights): array
    {
        $demands = [];
        $byId = [];

        foreach ($flights as $flight) {
            $window = $this->windowFor($flight);

            if ($window === null) {
                continue;
            }

            [$from, $until] = $window;
            $id = (string) $flight->getKey();

            $demands[] = ['flight_id' => $id, 'from' => $from, 'until' => $until];
            $byId[$id] = $flight;
        }

        if ($demands === []) {
            return ['allocations' => [], 'unallocated' => []];
        }

        $span = $this->spanOf($demands);

        $result = $this->allocator->allocate(
            demands: $demands,
            gates: $airport->gates(),
            closures: $this->closuresFor($airport, ...$span),
            existing: $this->existingAllocations($airport, $span[0], $span[1], array_keys($byId)),
        );

        $this->persist($result, $byId);

        return $result;
    }

    /**
     * The occupancy window for a flight.
     *
     * T is the moment the flight touches this airport — landing for an arrival,
     * take-off for a departure. The window sits around T by a configured offset,
     * because a departing aircraft holds its gate before it leaves, not after.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function windowFor(Flight $flight): ?array
    {
        $anchor = $flight->boardTime();

        if ($anchor === null) {
            return null;
        }

        $offset = $flight->direction === Flight::DIRECTION_ARRIVAL
            ? (int) config('dispatch.gate.offset_arrival_minutes')
            : (int) config('dispatch.gate.offset_departure_minutes');

        $from = CarbonImmutable::instance($anchor)->addMinutes($offset);

        return [$from, $from->addMinutes((int) config('dispatch.gate.occupancy_minutes'))];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function closuresFor(Airport $airport, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return GateClosure::forAirport($airport->icao)
            ->touching($from, $until)
            ->get()
            ->map(fn (GateClosure $c) => [
                'gate_code' => $c->gate_code,
                'from' => $c->from ? CarbonImmutable::instance($c->from) : null,
                'until' => $c->until ? CarbonImmutable::instance($c->until) : null,
                'reason' => (string) $c->reason,
            ])
            ->all();
    }

    /**
     * Allocations already in place that could clash, excluding the flights being
     * placed right now.
     *
     * @param  array<int, string>  $excludeIds
     * @return array<int, array<string, mixed>>
     */
    private function existingAllocations(Airport $airport, CarbonImmutable $from, CarbonImmutable $until, array $excludeIds): array
    {
        return Flight::overlapping($airport->icao, $from, $until)
            ->get()
            ->reject(fn (Flight $f) => in_array((string) $f->getKey(), $excludeIds, true))
            ->map(fn (Flight $f) => [
                'flight_id' => (string) $f->getKey(),
                'gate_code' => (string) $f->gate_code,
                'gate_terminal' => (string) $f->gate_terminal,
                'from' => CarbonImmutable::instance($f->occupies_from),
                'until' => CarbonImmutable::instance($f->occupies_until),
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $demands
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function spanOf(array $demands): array
    {
        $from = min(array_map(fn (array $d) => $d['from']->getTimestamp(), $demands));
        $until = max(array_map(fn (array $d) => $d['until']->getTimestamp(), $demands));

        return [CarbonImmutable::createFromTimestampUTC($from), CarbonImmutable::createFromTimestampUTC($until)];
    }

    /**
     * @param  array{allocations: array<int, array<string, mixed>>, unallocated: array<string, string>}  $result
     * @param  array<string, Flight>  $byId
     */
    private function persist(array $result, array $byId): void
    {
        foreach ($result['allocations'] as $allocation) {
            $byId[$allocation['flight_id']]?->forceFill([
                'gate_code' => $allocation['gate_code'],
                'gate_terminal' => $allocation['gate_terminal'],
                'occupies_from' => $allocation['from'],
                'occupies_until' => $allocation['until'],
                'allocation_status' => Flight::ALLOCATED,
                'allocation_reason' => null,
            ])->save();
        }

        foreach ($result['unallocated'] as $flightId => $reason) {
            $flight = $byId[$flightId] ?? null;

            if ($flight === null) {
                continue;
            }

            // The window is kept even when nothing was free: it records what was
            // asked for, and the next pass can try again without recomputing.
            [$from, $until] = $this->windowFor($flight) ?? [null, null];

            $flight->forceFill([
                'gate_code' => null,
                'gate_terminal' => null,
                'occupies_from' => $from,
                'occupies_until' => $until,
                'allocation_status' => Flight::UNALLOCATED,
                'allocation_reason' => $reason,
            ])->save();
        }
    }
}
