<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Console\Commands;

use App\Models\Airport;
use App\Models\Flight;
use App\Services\OpenSky\FlightImporter;
use App\Services\OpenSky\OpenSkyException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled collection of one day of traffic.
 *
 * The scheduler always runs it with its defaults: Frankfurt, today, both
 * directions. The options exist for manual testing, and deliberately not in the
 * environment — only the cron expression is configurable there. See the README.
 *
 * The default day is today, because gate planning is about the traffic being
 * handled now. Note that OpenSky reports flights it has already observed: a day
 * still in progress returns only the part that has happened so far, so an early
 * run collects little.
 *
 * Each direction costs 30 API credits out of a 4000/day allowance, so
 * collecting a single direction is the cheap way to test.
 */
class FetchFlights extends Command
{
    /** Frankfurt am Main, the airport this proof of concept is built around. */
    private const DEFAULT_AIRPORT = 'EDDF';

    protected $signature = 'flights:fetch
                            {--airport= : ICAO code to collect, defaults to '.self::DEFAULT_AIRPORT.' (Frankfurt)}
                            {--date= : Day to collect as Y-m-d, defaults to today}
                            {--direction= : Limit to "arrival" or "departure"; both when omitted}';

    protected $description = 'Collect one day of flights from OpenSky into MongoDB';

    public function handle(FlightImporter $importer): int
    {
        $icao = strtoupper((string) ($this->option('airport') ?: self::DEFAULT_AIRPORT));

        $airport = Airport::where('icao', $icao)->first();

        if (! $airport) {
            $this->error("{$icao} is not seeded. Run: php artisan db:seed");

            return self::FAILURE;
        }

        $direction = $this->resolveDirection();

        if ($direction === false) {
            $this->error('Invalid --direction. Expected "arrival" or "departure".');

            return self::FAILURE;
        }

        $day = $this->resolveDay($airport->timezone ?: 'UTC');

        if ($day === null) {
            $this->error('Invalid --date. Expected format Y-m-d, for example 2026-08-31.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Collecting %s (%s) for %s [%s] — %s',
            $airport->icao,
            $airport->name,
            $day->toDateString(),
            $airport->timezone,
            $direction ?? 'arrivals and departures',
        ));

        try {
            $written = $importer->import($airport, $day, $direction);
        } catch (OpenSkyException $e) {
            $this->error('OpenSky request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Stored %d flights.', $written));

        return self::SUCCESS;
    }

    /** @return string|null|false null for both directions, false when invalid */
    private function resolveDirection(): string|null|false
    {
        $input = $this->option('direction');

        if (blank($input)) {
            return null;
        }

        $direction = strtolower(trim((string) $input));

        return in_array($direction, [Flight::DIRECTION_ARRIVAL, Flight::DIRECTION_DEPARTURE], true)
            ? $direction
            : false;
    }

    private function resolveDay(string $timezone): ?CarbonImmutable
    {
        $input = $this->option('date');

        if (blank($input)) {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', (string) $input, $timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
