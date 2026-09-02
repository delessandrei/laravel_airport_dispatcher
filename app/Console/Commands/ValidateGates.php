<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAirport;
use App\Models\AllocationIssue;
use App\Services\Gates\GateValidator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Re-checks allocations that are current and records what stopped being valid.
 *
 * The window is expressed on the occupancy interval, not on the flight's anchor
 * time, so it stays correct when the occupancy duration or offset changes.
 */
class ValidateGates extends Command
{
    use ResolvesAirport;

    protected $signature = 'gates:validate
                            {--airport= : ICAO code, defaults to '.self::DEFAULT_AIRPORT.'}
                            {--all : Check every allocation, not just the current window}';

    protected $description = 'Check that current gate allocations are still valid';

    public function handle(GateValidator $validator): int
    {
        $airport = $this->resolveAirport();

        if (! $airport) {
            return self::FAILURE;
        }

        $now = CarbonImmutable::now();

        [$from, $until] = $this->option('all')
            ? [CarbonImmutable::createFromTimestampUTC(0), $now->addYears(50)]
            : [
                $now->subMinutes((int) config('dispatch.validate.grace_minutes')),
                $now->addMinutes((int) config('dispatch.validate.horizon_minutes')),
            ];

        $issues = $validator->validate($airport, $from, $until);
        $counts = array_count_values(array_column($issues, 'type'));

        AllocationIssue::create([
            'airport_icao' => strtoupper($airport->icao),
            'checked_at' => $now,
            'window_from' => $from,
            'window_until' => $until,
            'checked_count' => \App\Models\Flight::overlapping($airport->icao, $from, $until)->count(),
            'issue_count' => count($issues),
            'counts' => $counts,
            'issues' => $issues,
        ]);

        if ($issues === []) {
            $this->line("{$airport->icao}: allocations valid.");

            return self::SUCCESS;
        }

        $this->warn(sprintf('%s: %d problems found.', $airport->icao, count($issues)));

        foreach ($counts as $type => $count) {
            $this->warn("  {$count} × {$type}");
        }

        foreach (array_slice($issues, 0, 10) as $issue) {
            $this->line("    {$issue['callsign']} @ {$issue['gate_code']} — {$issue['detail']}");
        }

        return self::SUCCESS;
    }
}
