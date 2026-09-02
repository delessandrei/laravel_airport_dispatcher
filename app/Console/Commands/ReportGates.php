<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAirport;
use App\Models\AllocationReport;
use App\Services\Gates\GateReporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Hourly statistics, stored as one document per run. */
class ReportGates extends Command
{
    use ResolvesAirport;

    protected $signature = 'gates:report {--airport= : ICAO code, defaults to '.self::DEFAULT_AIRPORT.'}';

    protected $description = 'Record gate and movement statistics for the last hour and the day so far';

    public function handle(GateReporter $reporter): int
    {
        $airport = $this->resolveAirport();

        if (! $airport) {
            return self::FAILURE;
        }

        $at = CarbonImmutable::now();
        $report = $reporter->report($airport, $at);

        AllocationReport::create([
            'airport_icao' => strtoupper($airport->icao),
            'generated_at' => $at,
            'last_hour' => $report['last_hour'],
            'day_to_date' => $report['day_to_date'],
            'gates' => $report['gates'],
        ]);

        $this->info($airport->icao.' — '.$at->setTimezone($airport->timezone ?: 'UTC')->format('j M Y H:i'));

        foreach (['last_hour' => 'Last hour', 'day_to_date' => 'Day so far'] as $key => $label) {
            $m = $report[$key];
            $this->line(sprintf('  %-11s departures %-4d arrivals %-4d allocated %-4d unallocated %d',
                $label, $m['departures'], $m['arrivals'], $m['allocated'], $m['unallocated']));
        }

        $g = $report['gates'];
        $this->line(sprintf('  %-11s %d total, %d free, %d in use, %d closed, %d used today',
            'Gates', $g['total'], $g['free_now'], $g['in_use_now'], $g['closed_now'], $g['distinct_used_today']));

        foreach ($g['top_gates'] as $row) {
            $this->line("      {$row['gate']}: {$row['flights']} flights");
        }

        return self::SUCCESS;
    }
}
