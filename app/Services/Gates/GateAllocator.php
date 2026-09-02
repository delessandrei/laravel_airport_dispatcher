<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\Gates;

use Carbon\CarbonImmutable;

/**
 * Places flights at gates.
 *
 * Deliberately free of the database, the clock and configuration: everything it
 * needs arrives as arguments, and the same input always produces the same
 * output. That is what makes the allocation rules testable on their own, and it
 * is where the important behaviour of this application lives.
 *
 * The policy is first-fit over a stable gate order: gates are tried terminal by
 * terminal, in natural number order, and the first one that is neither closed
 * nor already taken wins. It is not optimal, and is not meant to be — a smarter
 * policy replaces sortGates().
 *
 * Everything is a plain array, in the shape it is stored in:
 *
 *   demand     ['flight_id' => .., 'from' => CarbonImmutable, 'until' => ..]
 *   gate       ['code' => 'A1', 'terminal' => 'T1', 'type' => 'jetbridge']
 *   closure    ['gate_code' => 'A1', 'from' => ..|null, 'until' => ..|null]
 *   allocation ['flight_id' => .., 'gate_code' => .., 'gate_terminal' => ..,
 *               'from' => .., 'until' => ..]
 *
 * Intervals are half-open, [from, until): a flight starting exactly when
 * another ends may reuse the gate.
 */
final class GateAllocator
{
    public const NO_GATES = 'no_gates';

    public const ALL_GATES_CLOSED = 'all_gates_closed';

    public const NO_FREE_GATE = 'no_free_gate';

    /**
     * @param  array<int, array<string, mixed>>  $demands   flights wanting a gate
     * @param  array<int, array<string, string>>  $gates
     * @param  array<int, array<string, mixed>>  $closures
     * @param  array<int, array<string, mixed>>  $existing  already placed, and to be respected
     * @return array{allocations: array<int, array<string, mixed>>, unallocated: array<string, string>}
     */
    public function allocate(array $demands, array $gates, array $closures = [], array $existing = []): array
    {
        if ($gates === []) {
            return [
                'allocations' => [],
                'unallocated' => array_fill_keys(array_column($demands, 'flight_id'), self::NO_GATES),
            ];
        }

        // Gate code => intervals already spoken for, seeded with existing placements.
        $taken = [];

        foreach ($existing as $allocation) {
            $taken[$allocation['gate_code']][] = [$allocation['from'], $allocation['until']];
        }

        // Earliest demand first, flight id to break ties, so the result is
        // reproducible rather than dependent on how the rows came out of Mongo.
        usort($demands, fn (array $a, array $b) => [$a['from']->getTimestamp(), $a['flight_id']]
            <=> [$b['from']->getTimestamp(), $b['flight_id']]);

        $gates = $this->sortGates($gates);

        $allocations = [];
        $unallocated = [];

        foreach ($demands as $demand) {
            $openGates = 0;
            $placed = false;

            foreach ($gates as $gate) {
                if ($this->isClosed($gate['code'], $demand, $closures)) {
                    continue;
                }

                $openGates++;

                if ($this->isTaken($gate['code'], $demand, $taken)) {
                    continue;
                }

                $allocations[] = [
                    'flight_id' => $demand['flight_id'],
                    'gate_code' => $gate['code'],
                    'gate_terminal' => $gate['terminal'],
                    'from' => $demand['from'],
                    'until' => $demand['until'],
                ];

                $taken[$gate['code']][] = [$demand['from'], $demand['until']];
                $placed = true;

                break;
            }

            if (! $placed) {
                // Being closed everywhere and being full everywhere are different
                // problems, and the report should be able to tell them apart.
                $unallocated[$demand['flight_id']] = $openGates === 0
                    ? self::ALL_GATES_CLOSED
                    : self::NO_FREE_GATE;
            }
        }

        return ['allocations' => $allocations, 'unallocated' => $unallocated];
    }

    /**
     * Whether a closure makes a gate unusable over a window.
     *
     * Static because the validator asks the same question of allocations that
     * were made earlier, and the answer must not differ between the two.
     * Either end of a closure may be null: no start blocks everything before
     * it, no end blocks everything after.
     *
     * @param  array<string, mixed>  $closure
     */
    public static function closureBlocks(array $closure, string $gateCode, CarbonImmutable $from, CarbonImmutable $until): bool
    {
        if ($closure['gate_code'] !== $gateCode) {
            return false;
        }

        $startsBeforeItEnds = $closure['from'] === null || $closure['from']->lessThan($until);
        $endsAfterItStarts = $closure['until'] === null || $closure['until']->greaterThan($from);

        return $startsBeforeItEnds && $endsAfterItStarts;
    }

    /**
     * Terminal, then gate number in natural order, so A2 comes before A10.
     * Deliberately plain, and the place to change when the policy grows.
     *
     * @param  array<int, array<string, string>>  $gates
     * @return array<int, array<string, string>>
     */
    private function sortGates(array $gates): array
    {
        usort($gates, fn (array $a, array $b) => $a['terminal'] <=> $b['terminal']
            ?: strnatcmp($a['code'], $b['code']));

        return $gates;
    }

    /**
     * @param  array<string, mixed>  $demand
     * @param  array<int, array<string, mixed>>  $closures
     */
    private function isClosed(string $gateCode, array $demand, array $closures): bool
    {
        foreach ($closures as $closure) {
            if (self::closureBlocks($closure, $gateCode, $demand['from'], $demand['until'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $demand
     * @param  array<string, array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>>  $taken
     */
    private function isTaken(string $gateCode, array $demand, array $taken): bool
    {
        foreach ($taken[$gateCode] ?? [] as [$from, $until]) {
            if ($demand['from']->lessThan($until) && $demand['until']->greaterThan($from)) {
                return true;
            }
        }

        return false;
    }
}
