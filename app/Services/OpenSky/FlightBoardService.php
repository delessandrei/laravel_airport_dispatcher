<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\OpenSky;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightImport;
use Carbon\CarbonImmutable;

/**
 * Assembles the flight board for one airport-day.
 *
 * MongoDB is the source of truth. The scheduler fills it for Frankfurt; any
 * other airport-day is collected on first view and then served from the
 * database for good. That is what protects the OpenSky credit allowance —
 * there is no response cache any more, because a stored day never needs
 * fetching twice.
 *
 * The day window is computed in the airport's own timezone: "flights on the
 * 28th at Heathrow" means the 28th in London, not in UTC.
 */
class FlightBoardService
{
    /** Movements shown either side of now on the current day's board. */
    private const BOARD_WINDOW = 20;

    private string $sort = 'time';

    private string $sortDirection = 'desc';

    /** False when the reader asked to see the whole day rather than a window. */
    private bool $window = true;

    public function __construct(
        private readonly OpenSkyClient $client,
        private readonly DemoFlightProvider $demo,
        private readonly FlightImporter $importer,
    ) {}

    /**
     * @param  string  $sort  'time' or 'gate'
     * @param  string  $sortDirection  'asc' or 'desc'
     */
    public function forAirport(
        Airport $airport,
        CarbonImmutable $date,
        string $sort = 'time',
        string $sortDirection = 'desc',
        bool $window = true,
    ): FlightBoard {
        $this->sort = $sort;
        $this->sortDirection = $sortDirection;
        $this->window = $window;

        $timezone = $airport->timezone ?: 'UTC';
        $dayStart = $date->setTimezone($timezone)->startOfDay();
        $dayEnd = $dayStart->endOfDay();
        $day = $dayStart->toDateString();
        $icao = strtoupper($airport->icao);

        $import = FlightImport::forDay($icao, $day)->first();

        // Collected in full already: served from the database, no API call. The
        // current day is not refetched either — the scheduler keeps it current.
        if ($import && $import->isComplete()) {
            return $this->fromDatabase($icao, $day, $import, $dayStart, $timezone);
        }

        // OpenSky reports what has already flown, so a future day has nothing.
        if ($dayStart->isFuture()) {
            return new FlightBoard([], [], isDemo: false,
                notice: 'OpenSky reports observed traffic, so a future date has nothing to show yet.');
        }

        if (! $this->client->isConfigured()) {
            return $this->demoBoard($icao, $dayStart, $dayEnd, $timezone,
                'Showing generated demo traffic. Set OPENSKY_CLIENT_ID and OPENSKY_CLIENT_SECRET in .env for live data.');
        }

        // Configured, but this day has never been collected. Opening a page must
        // not spend credits on its own: collection is an explicit action.
        return new FlightBoard([], [], isDemo: false,
            notice: 'This day has not been collected yet. Pull it from OpenSky to see the flights.');
    }

    private function fromDatabase(string $icao, string $day, ?FlightImport $import, CarbonImmutable $dayStart, string $timezone): FlightBoard
    {
        return $this->build(
            $this->read($icao, $day, Flight::DIRECTION_ARRIVAL),
            $this->read($icao, $day, Flight::DIRECTION_DEPARTURE),
            $dayStart, $timezone, isDemo: false,
            creditsRemaining: $this->client->creditsRemaining(),
            importedAt: $import?->imported_at,
        );
    }

    /**
     * Trims the current day to a window around now, the way a terminal board
     * shows the movements just gone and the ones coming up. A completed day is
     * left whole: there is no "now" inside it to centre on.
     *
     * @param  array<int, Flight>  $arrivals
     * @param  array<int, Flight>  $departures
     */
    private function build(
        array $arrivals,
        array $departures,
        CarbonImmutable $dayStart,
        string $timezone,
        bool $isDemo,
        ?string $notice = null,
        ?int $creditsRemaining = null,
        $importedAt = null,
    ): FlightBoard {
        $isToday = $dayStart->isSameDay(CarbonImmutable::now($timezone)) && $this->window;
        $pivot = $isToday ? CarbonImmutable::now($timezone) : null;

        // Windowing happens first and always by time: "the last twenty and the
        // next twenty" only means something in time order. Whatever survives is
        // then sorted for display.
        return new FlightBoard(
            arrivals: $this->sortRows($isToday ? $this->window($arrivals, $pivot) : $arrivals),
            departures: $this->sortRows($isToday ? $this->window($departures, $pivot) : $departures),
            isDemo: $isDemo,
            notice: $notice,
            creditsRemaining: $creditsRemaining,
            importedAt: $importedAt,
            totalArrivals: count($arrivals),
            totalDepartures: count($departures),
            isWindowed: $isToday,
            pivot: $pivot,
            sort: $this->sort,
            sortDirection: $this->sortDirection,
        );
    }

    /**
     * Orders the rows the reader asked for.
     *
     * Flights with no gate always sink to the bottom when sorting by gate: they
     * are the exceptions, and burying them among the allocated ones in
     * ascending order would hide exactly what someone is looking for.
     *
     * @param  array<int, Flight>  $flights
     * @return array<int, Flight>
     */
    private function sortRows(array $flights): array
    {
        $descending = $this->sortDirection === 'desc';

        usort($flights, function (Flight $a, Flight $b) use ($descending) {
            if ($this->sort === 'gate') {
                $missing = ($a->gate_code === null ? 1 : 0) <=> ($b->gate_code === null ? 1 : 0);

                $result = $missing !== 0
                    ? $missing
                    : strnatcmp((string) $a->gate_code, (string) $b->gate_code);

                // The nulls-last rule survives the direction flip.
                return $missing !== 0 ? $missing : ($descending ? -$result : $result);
            }

            $result = ($a->boardTime()?->getTimestamp() ?? 0) <=> ($b->boardTime()?->getTimestamp() ?? 0);

            return $descending ? -$result : $result;
        });

        return $flights;
    }

    /**
     * @param  array<int, Flight>  $flights  ordered by board time, ascending
     * @return array<int, Flight>
     */
    private function window(array $flights, CarbonImmutable $pivot): array
    {
        $past = [];
        $upcoming = [];

        foreach ($flights as $flight) {
            $time = $flight->boardTime();

            if ($time !== null && $time->greaterThan($pivot)) {
                $upcoming[] = $flight;
            } else {
                $past[] = $flight;
            }
        }

        return array_merge(
            array_slice($past, -self::BOARD_WINDOW),
            array_slice($upcoming, 0, self::BOARD_WINDOW),
        );
    }

    /**
     * @return array<int, Flight>
     */
    private function read(string $icao, string $day, string $direction): array
    {
        return Flight::forBoard($icao, $day, $direction)
            ->orderBy($direction === Flight::DIRECTION_ARRIVAL ? 'last_seen' : 'first_seen')
            ->get()
            ->all();
    }

    private function demoBoard(string $icao, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, string $timezone, string $notice): FlightBoard
    {
        return $this->build(
            $this->demo->arrivals($icao, $dayStart, $dayEnd),
            $this->demo->departures($icao, $dayStart, $dayEnd),
            $dayStart, $timezone, isDemo: true, notice: $notice,
        );
    }
}
