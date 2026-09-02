<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Http\Controllers;

use App\Models\Airport;
use App\Models\GateClosure;
use App\Services\OpenSky\FlightBoardService;
use App\Services\OpenSky\FlightImporter;
use App\Services\OpenSky\OpenSkyException;
use Illuminate\Http\RedirectResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AirportController extends Controller
{
    /** Countries covered by this proof of concept. */
    private const COUNTRIES = [
        'RO' => 'Romania',
        'DE' => 'Germany',
        'GB' => 'United Kingdom',
    ];

    public function index(Request $request): View
    {
        $scope = $request->query('scope') === 'europe' ? 'europe' : 'romania';

        $country = strtoupper((string) $request->query('country', ''));
        $country = array_key_exists($country, self::COUNTRIES) ? $country : null;

        // Romania is the default view; Europe asks for a country first.
        $selected = $scope === 'romania' ? 'RO' : $country;

        $airports = $selected
            ? Airport::inCountry($selected)->orderBy('city')->get()
            : collect();


        $counts = Airport::raw(fn ($collection) => $collection->aggregate([
            ['$group' => ['_id' => '$country_code', 'total' => ['$sum' => 1]]],
        ]))->pluck('total', '_id');

        return view('home', [
            'scope' => $scope,
            'countries' => self::COUNTRIES,
            'selectedCountry' => $selected,
            'airports' => $airports,
            'counts' => $counts,
        ]);
    }

    public function show(Request $request, string $icao, FlightBoardService $flights): View
    {
        $airport = Airport::where('icao', strtoupper($icao))->firstOrFail();
        $date = $this->resolveDate($request, $airport->timezone ?: 'UTC');
        // Only the time and gate columns are sortable; anything else falls back
        // to time, so a hand-edited URL cannot produce a meaningless order.
        $sort = in_array($request->query('sort'), ['time', 'gate'], true)
            ? $request->query('sort')
            : 'time';

        $sortDirection = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        // The current day is trimmed to a window around now unless the reader
        // asks for all of it.
        $window = $request->query('window') !== 'all';

        $board = $flights->forAirport($airport, $date, $sort, $sortDirection, $window);

        $tab = $request->query('board') === 'departures' ? 'departures' : 'arrivals';

        return view('airports.show', [
            'airport' => $airport,
            'board' => $board,
            'date' => $date,
            'tab' => $tab,
            'closures' => $this->closuresOn($airport, $date),
            'windowed' => $window,
        ]);
    }

    /**
     * Pulls one airport-day from OpenSky on request.
     *
     * Collection is a deliberate action rather than a side effect of opening a
     * page, so browsing never spends credits. Each pull costs sixty.
     */
    public function collect(Request $request, string $icao, FlightImporter $importer): RedirectResponse
    {
        $airport = Airport::where('icao', strtoupper($icao))->firstOrFail();
        $date = $this->resolveDate($request, $airport->timezone ?: 'UTC');

        try {
            $written = $importer->import($airport, $date);

            $status = $written === 0
                ? 'OpenSky reported no flights for this day.'
                : "Collected {$written} flights from OpenSky.";
        } catch (OpenSkyException $e) {
            $status = 'OpenSky request failed: '.$e->getMessage();
        }

        return redirect()
            ->route('airports.show', array_filter([
                $airport->icao,
                'date' => $date->toDateString(),
                'board' => $request->input('board'),
                'sort' => $request->input('sort'),
                'dir' => $request->input('dir'),
                'window' => $request->input('window'),
            ]))
            ->with('status', $status);
    }

    private function closuresOn(Airport $airport, CarbonImmutable $date)
    {
        $timezone = $airport->timezone ?: 'UTC';
        $dayStart = $date->setTimezone($timezone)->startOfDay();
        $now = CarbonImmutable::now($timezone);

        [$from, $until] = $dayStart->isSameDay($now)
            ? [$now, $now->addSecond()]
            : [$dayStart, $dayStart->endOfDay()];

        return GateClosure::forAirport($airport->icao)
            ->touching($from, $until)
            ->orderBy('from')
            ->get()
            ->groupBy('gate_code')
            ->map(fn ($forGate) => $forGate->first());
    }

    /** Reads the query string on a GET and the body on the collect POST. */
    private function resolveDate(Request $request, string $timezone): CarbonImmutable
    {
        $input = (string) $request->input('date', '');

        try {
            return $input !== ''
                ? CarbonImmutable::createFromFormat('Y-m-d', $input, $timezone)->startOfDay()
                : CarbonImmutable::now($timezone)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now($timezone)->startOfDay();
        }
    }
}
