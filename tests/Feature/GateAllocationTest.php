<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\AllocationIssue;
use App\Models\AllocationReport;
use App\Models\Flight;
use App\Models\FlightImport;
use App\Models\GateClosure;
use App\Services\Gates\GateAllocationService;
use App\Services\OpenSky\FlightBoardService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class GateAllocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Airport::class, Flight::class, FlightImport::class, GateClosure::class, AllocationIssue::class, AllocationReport::class] as $model) {
            $model::truncate();
        }

        Airport::create([
            'icao' => 'EDDF', 'iata' => 'FRA', 'name' => 'Frankfurt am Main',
            'city' => 'Frankfurt', 'country_code' => 'DE', 'country_name' => 'Germany',
            'timezone' => 'Europe/Berlin', 'latitude' => 50.0379, 'longitude' => 8.5622,
            'terminals' => [[
                'code' => 'T1', 'name' => 'Terminal 1',
                'gates' => [
                    ['code' => 'A1', 'terminal' => 'T1', 'type' => 'jetbridge'],
                    ['code' => 'A2', 'terminal' => 'T1', 'type' => 'jetbridge'],
                ],
            ]],
        ]);

        config([
            'dispatch.gate.occupancy_minutes' => 90,
            'dispatch.gate.offset_departure_minutes' => -90,
            'dispatch.gate.offset_arrival_minutes' => 0,
        ]);
    }

    public function test_a_departure_holds_its_gate_before_take_off(): void
    {
        $flight = $this->departure('DLH100', '12:00');

        $this->service()->allocatePending($this->airport());

        $flight->refresh();

        $this->assertSame('A1', $flight->gate_code);
        $this->assertSame(Flight::ALLOCATED, $flight->allocation_status);
        $this->assertSame('10:30', $flight->occupies_from->format('H:i'), 'The gate is held before departure, not after.');
        $this->assertSame('12:00', $flight->occupies_until->format('H:i'));
    }

    public function test_an_arrival_holds_its_gate_after_landing(): void
    {
        $flight = $this->arrival('DLH200', '12:00');

        $this->service()->allocatePending($this->airport());

        $flight->refresh();

        $this->assertSame('12:00', $flight->occupies_from->format('H:i'));
        $this->assertSame('13:30', $flight->occupies_until->format('H:i'));
    }

    public function test_a_third_overlapping_flight_cannot_be_placed(): void
    {
        $this->departure('A', '12:00');
        $this->departure('B', '12:10');
        $this->departure('C', '12:20');

        $result = $this->service()->allocatePending($this->airport());

        $this->assertSame(2, count($result['allocations']), 'Only two gates exist.');
        $this->assertSame(1, count($result['unallocated']));

        $stuck = Flight::where('allocation_status', Flight::UNALLOCATED)->firstOrFail();

        $this->assertSame('no_free_gate', $stuck->allocation_reason);
        $this->assertNull($stuck->gate_code);
    }

    public function test_closing_a_gate_moves_the_flight_standing_there(): void
    {
        $flight = $this->departure('DLH300', '12:00');
        $this->service()->allocatePending($this->airport());

        $this->assertSame('A1', $flight->refresh()->gate_code);

        $this->artisan('gates:close', [
            '--airport' => 'EDDF', '--gate' => 'A1', '--reason' => 'repairs',
            '--from' => '2026-09-01 00:00', '--until' => '2026-09-02 00:00',
        ])->assertSuccessful();

        $this->assertSame('A2', $flight->refresh()->gate_code, 'The flight should have been moved, not left on a closed gate.');
    }

    public function test_reopening_a_gate_lets_a_stuck_flight_through(): void
    {
        // Anchored ahead of now on purpose: reopening a gate frees it from that
        // moment on, and cannot retroactively open a window already past.
        $flight = $this->departureAt('DLH400', CarbonImmutable::now()->addHours(4));

        GateClosure::create(['airport_icao' => 'EDDF', 'gate_code' => 'A1', 'from' => null, 'until' => null, 'reason' => 'closed']);
        GateClosure::create(['airport_icao' => 'EDDF', 'gate_code' => 'A2', 'from' => null, 'until' => null, 'reason' => 'closed']);

        $this->service()->allocatePending($this->airport());

        $this->assertSame('all_gates_closed', $flight->refresh()->allocation_reason);

        $this->artisan('gates:open', ['--airport' => 'EDDF', '--gate' => 'A1'])->assertSuccessful();
        $this->artisan('gates:allocate', ['--airport' => 'EDDF'])->assertSuccessful();

        $this->assertSame('A1', $flight->refresh()->gate_code);
    }

    public function test_the_validator_records_a_closed_gate_it_was_not_told_about(): void
    {
        $flight = $this->departure('DLH500', '12:00');
        $this->service()->allocatePending($this->airport());

        // Written straight to the database, bypassing the command that relocates.
        GateClosure::create([
            'airport_icao' => 'EDDF', 'gate_code' => $flight->refresh()->gate_code,
            'from' => null, 'until' => null, 'reason' => 'silent closure',
        ]);

        $this->artisan('gates:validate', ['--airport' => 'EDDF', '--all' => true])->assertSuccessful();

        $issue = AllocationIssue::latest('checked_at')->firstOrFail();

        $this->assertSame(1, $issue->issue_count);
        $this->assertFalse($issue->hasNoIssues());
        $this->assertSame(AllocationIssue::CLOSED_GATE, $issue->issues[0]['type']);
    }

    public function test_the_report_records_movements_and_gate_use(): void
    {
        // Midday today in the airport's timezone. Anchoring to now plus a few
        // hours looks clock independent but is not: run late in the evening it
        // lands on tomorrow, and the day-to-date figures then count nothing.
        $this->departureAt('DLH600', CarbonImmutable::now('Europe/Berlin')->startOfDay()->addHours(12));
        $this->service()->allocatePending($this->airport());

        $this->artisan('gates:report', ['--airport' => 'EDDF'])->assertSuccessful();

        $report = AllocationReport::latest('generated_at')->firstOrFail();

        $this->assertSame(2, $report->gates['total']);
        $this->assertSame(1, $report->gates['distinct_used_today']);
        $this->assertSame('A1', $report->gates['top_gates'][0]['gate']);
    }

    public function test_sorting_by_gate_sinks_unallocated_flights_to_the_bottom(): void
    {
        // Three flights at once, two gates: one is left without.
        $this->departure('A', '12:00');
        $this->departure('B', '12:10');
        $this->departure('C', '12:20');

        $this->service()->allocatePending($this->airport());

        // Without this the board treats the day as never collected and serves
        // generated traffic instead of the flights just created.
        FlightImport::create([
            'airport_icao' => 'EDDF',
            'day' => '2026-09-01',
            'flights_count' => 3,
            'directions' => [Flight::DIRECTION_ARRIVAL, Flight::DIRECTION_DEPARTURE],
            'imported_at' => now(),
        ]);

        foreach (['asc', 'desc'] as $direction) {
            $board = app(FlightBoardService::class)->forAirport(
                $this->airport(),
                CarbonImmutable::parse('2026-09-01', 'Europe/Berlin'),
                sort: 'gate',
                sortDirection: $direction,
            );

            $gates = array_map(fn (Flight $f) => $f->gate_code, $board->departures);

            $this->assertNull(
                end($gates),
                "Sorting {$direction} by gate must still leave the unallocated flight last.",
            );
            $this->assertNotNull($gates[0]);
        }
    }

    private function departure(string $callsign, string $time): Flight
    {
        return $this->flight($callsign, Flight::DIRECTION_DEPARTURE, $time);
    }

    /** For the cases whose meaning depends on where "now" is. */
    private function departureAt(string $callsign, CarbonImmutable $at): Flight
    {
        return $this->store($callsign, Flight::DIRECTION_DEPARTURE, $at);
    }

    private function arrival(string $callsign, string $time): Flight
    {
        return $this->flight($callsign, Flight::DIRECTION_ARRIVAL, $time);
    }

    private function flight(string $callsign, string $direction, string $time): Flight
    {
        return $this->store($callsign, $direction, CarbonImmutable::parse('2026-09-01 '.$time, 'UTC'));
    }

    private function store(string $callsign, string $direction, CarbonImmutable $at): Flight
    {
        return Flight::create([
            'airport_icao' => 'EDDF',
            'direction' => $direction,
            'day' => $at->toDateString(),
            'icao24' => strtolower(substr(md5($callsign), 0, 6)),
            'callsign' => $callsign,
            'departure_airport' => $direction === Flight::DIRECTION_DEPARTURE ? 'EDDF' : 'LROP',
            'arrival_airport' => $direction === Flight::DIRECTION_DEPARTURE ? 'LROP' : 'EDDF',
            'first_seen' => $direction === Flight::DIRECTION_DEPARTURE ? $at : $at->subHours(2),
            'last_seen' => $direction === Flight::DIRECTION_DEPARTURE ? $at->addHours(2) : $at,
        ]);
    }

    private function airport(): Airport
    {
        return Airport::where('icao', 'EDDF')->firstOrFail();
    }

    private function service(): GateAllocationService
    {
        return app(GateAllocationService::class);
    }
}
