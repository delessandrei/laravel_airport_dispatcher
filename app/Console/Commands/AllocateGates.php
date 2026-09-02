<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAirport;
use App\Services\Gates\GateAllocationService;
use Illuminate\Console\Command;

/**
 * Places every flight that has no gate yet.
 *
 * Runs often, and usually finds nothing to do: new flights only appear when the
 * collector runs. That is fine — the query is a single indexed lookup.
 */
class AllocateGates extends Command
{
    use ResolvesAirport;

    protected $signature = 'gates:allocate {--airport= : ICAO code, defaults to '.self::DEFAULT_AIRPORT.'}';

    protected $description = 'Allocate gates to flights that do not have one yet';

    public function handle(GateAllocationService $allocation): int
    {
        $airport = $this->resolveAirport();

        if (! $airport) {
            return self::FAILURE;
        }

        $result = $allocation->allocatePending($airport);

        $allocated = count($result['allocations']);
        $unallocated = count($result['unallocated']);

        if ($allocated === 0 && $unallocated === 0) {
            $this->line("{$airport->icao}: nothing waiting for a gate.");

            return self::SUCCESS;
        }

        $this->info(sprintf('%s: allocated %d, could not place %d.',
            $airport->icao, $allocated, $unallocated));

        foreach (array_count_values($result['unallocated']) as $reason => $count) {
            $this->warn("  {$count} × {$reason}");
        }

        return self::SUCCESS;
    }
}
