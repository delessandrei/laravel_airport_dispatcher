<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\OpenSky;

use App\Models\Airport;
use App\Models\Flight as FlightRecord;
use App\Models\FlightImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fetches one airport-day from OpenSky and writes it into MongoDB.
 *
 * This is the only place flights are persisted. Both callers use it: the
 * scheduled command, and the flight board when it is asked for a day nobody
 * has collected yet.
 *
 * Every run spends credits, so two guards apply. Writes are upserts keyed on
 * the natural identity of a leg, making a repeated run idempotent rather than
 * duplicating documents. And a short lock stops two concurrent requests for the
 * same airport-day from both paying for the same data.
 */
class FlightImporter
{
    /** How long a single import may hold the lock for an airport-day. */
    private const LOCK_SECONDS = 120;

    public function __construct(private readonly OpenSkyClient $client) {}

    /**
     * @param  string|null  $direction  one of Flight::DIRECTION_*, or null for both
     * @return int number of flight documents written or refreshed
     */
    public function import(Airport $airport, CarbonImmutable $day, ?string $direction = null): int
    {
        $directions = $direction !== null
            ? [$direction]
            : [FlightRecord::DIRECTION_ARRIVAL, FlightRecord::DIRECTION_DEPARTURE];

        $timezone = $airport->timezone ?: 'UTC';
        $dayStart = $day->setTimezone($timezone)->startOfDay();
        $dayEnd = $dayStart->endOfDay();

        $lock = Cache::lock("opensky:import:{$airport->icao}:{$dayStart->toDateString()}", self::LOCK_SECONDS);

        // Someone else is already paying for this exact window; let them finish.
        if (! $lock->get()) {
            Log::info('Import already running, skipping', [
                'airport' => $airport->icao,
                'day' => $dayStart->toDateString(),
            ]);

            return 0;
        }

        try {
            $written = 0;

            foreach ($directions as $each) {
                $flights = $each === FlightRecord::DIRECTION_ARRIVAL
                    ? $this->client->arrivals($airport->icao, $dayStart, $dayEnd)
                    : $this->client->departures($airport->icao, $dayStart, $dayEnd);

                $written += $this->store($airport, $dayStart, $each, $flights);
            }

            $this->recordImport($airport, $dayStart, $directions);

            return $written;
        } finally {
            $lock->release();
        }
    }

    /**
     * Records that this airport-day was collected, and which halves of it.
     *
     * Recorded even when nothing came back: "collected, found nothing" must be
     * distinguishable from "never collected". Directions accumulate across runs,
     * so fetching one half now and the other later still adds up to a full day.
     *
     * @param  array<int, string>  $directions
     */
    private function recordImport(Airport $airport, CarbonImmutable $dayStart, array $directions): void
    {
        $icao = strtoupper($airport->icao);
        $day = $dayStart->toDateString();

        $existing = FlightImport::forDay($icao, $day)->first();

        // A record with no directions field predates the tracking and always
        // covered both halves — the same reading isComplete() uses. Treating it
        // as an empty list here would silently downgrade a complete day.
        $known = $existing === null
            ? []
            : ($existing->directions ?? [FlightRecord::DIRECTION_ARRIVAL, FlightRecord::DIRECTION_DEPARTURE]);

        $merged = array_values(array_unique(array_merge($known, $directions)));
        sort($merged);

        FlightImport::updateOrCreate(
            ['airport_icao' => $icao, 'day' => $day],
            [
                'flights_count' => FlightRecord::where('airport_icao', $icao)->where('day', $day)->count(),
                'directions' => $merged,
                'imported_at' => now(),
            ],
        );
    }

    /**
     * @param  array<int, Flight>  $flights
     */
    private function store(Airport $airport, CarbonImmutable $dayStart, string $direction, array $flights): int
    {
        $day = $dayStart->toDateString();
        $written = 0;

        foreach ($flights as $flight) {
            FlightRecord::updateOrCreate(
                [
                    'airport_icao' => strtoupper($airport->icao),
                    'direction' => $direction,
                    'icao24' => $flight->icao24,
                    'first_seen' => $flight->departureTime,
                ],
                [
                    'day' => $day,
                    'callsign' => $flight->callsign,
                    'departure_airport' => $flight->departureAirport,
                    'arrival_airport' => $flight->arrivalAirport,
                    'last_seen' => $flight->arrivalTime,
                    'departure_horiz_distance' => $flight->departureHorizDistance,
                    'departure_vert_distance' => $flight->departureVertDistance,
                    'arrival_horiz_distance' => $flight->arrivalHorizDistance,
                    'arrival_vert_distance' => $flight->arrivalVertDistance,
                    'departure_candidates' => $flight->departureCandidates,
                    'arrival_candidates' => $flight->arrivalCandidates,
                ],
            );

            $written++;
        }

        return $written;
    }
}
