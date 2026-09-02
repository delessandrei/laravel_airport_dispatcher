<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\GateClosure;
use App\Services\OpenSky\FlightBoardService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AirportBrowsingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Airport::truncate();
        GateClosure::truncate();

        Airport::create([
            'icao' => 'LROP', 'iata' => 'OTP', 'name' => 'Henri Coandă International',
            'city' => 'Bucharest', 'country_code' => 'RO', 'country_name' => 'Romania',
            'timezone' => 'Europe/Bucharest', 'latitude' => 44.5722, 'longitude' => 26.1022,
            'terminals' => [[
                'code' => 'T1', 'name' => 'Terminal 1',
                'gates' => [
                    ['code' => 'A1', 'terminal' => 'T1', 'type' => 'jetbridge'],
                    ['code' => 'A2', 'terminal' => 'T1', 'type' => 'remote'],
                ],
            ]],
        ]);

        Airport::create([
            'icao' => 'EDDF', 'iata' => 'FRA', 'name' => 'Frankfurt am Main',
            'city' => 'Frankfurt', 'country_code' => 'DE', 'country_name' => 'Germany',
            'timezone' => 'Europe/Berlin', 'latitude' => 50.0379, 'longitude' => 8.5622,
            'terminals' => [],
        ]);
    }

    public function test_home_lists_romanian_airports_by_default(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('LROP')
            ->assertDontSee('EDDF');
    }

    public function test_europe_scope_offers_a_country_picker(): void
    {
        $this->get('/?scope=europe')
            ->assertOk()
            ->assertSee('Select a country')
            ->assertSee('Germany');
    }

    public function test_selecting_a_country_lists_only_its_airports(): void
    {
        $this->get('/?scope=europe&country=DE')
            ->assertOk()
            ->assertSee('EDDF')
            ->assertDontSee('LROP');
    }

    public function test_an_unknown_country_falls_back_to_romania(): void
    {
        $this->get('/?scope=europe&country=ZZ')
            ->assertOk()
            ->assertDontSee('EDDF');
    }

    public function test_airport_page_shows_terminals_gates_and_a_flight_board(): void
    {
        $response = $this->get('/airports/LROP');

        $response->assertOk()
            ->assertSee('Henri Coandă International')
            ->assertSee('Terminal 1')
            ->assertSee('data-gate="A1"', escape: false)
            ->assertSee('data-gate="A2"', escape: false)
            ->assertSee('Arrivals');
    }

    public function test_flight_board_is_labelled_as_demo_without_opensky_credentials(): void
    {
        config(['services.opensky.client_id' => null, 'services.opensky.client_secret' => null]);

        $this->get('/airports/LROP')
            ->assertOk()
            ->assertSee('Demo data');
    }

    public function test_departures_tab_can_be_selected(): void
    {
        $this->get('/airports/LROP?board=departures')
            ->assertOk()
            ->assertSee('Departs');
    }

    public function test_todays_board_is_windowed_around_now(): void
    {
        $board = app(FlightBoardService::class)
            ->forAirport(Airport::where('icao', 'LROP')->first(), CarbonImmutable::now('Europe/Bucharest'));

        $this->assertTrue($board->isWindowed, 'The current day should be windowed.');
        $this->assertNotNull($board->pivot, 'A windowed board needs a pivot to split on.');

        // Never more than the last twenty plus the next twenty.
        $this->assertLessThanOrEqual(40, count($board->arrivals));
        $this->assertLessThanOrEqual(40, count($board->departures));

        // Totals describe the whole day, not the trimmed view.
        $this->assertGreaterThanOrEqual(count($board->arrivals), $board->totalArrivals);
    }

    public function test_a_completed_day_is_not_windowed(): void
    {
        $board = app(FlightBoardService::class)->forAirport(
            Airport::where('icao', 'LROP')->first(),
            CarbonImmutable::now('Europe/Bucharest')->subDays(3),
        );

        $this->assertFalse($board->isWindowed, 'A day that has ended has no "now" to centre on.');
        $this->assertNull($board->pivot);
        $this->assertSame($board->totalArrivals, count($board->arrivals), 'A completed day is shown whole.');
    }

    public function test_a_future_day_never_calls_the_api(): void
    {
        $board = app(FlightBoardService::class)->forAirport(
            Airport::where('icao', 'LROP')->first(),
            CarbonImmutable::now('Europe/Bucharest')->addWeek(),
        );

        $this->assertSame(0, $board->total());
        $this->assertFalse($board->isDemo);
        $this->assertStringContainsString('future date', (string) $board->notice);
    }

    public function test_a_closed_gate_is_marked_with_its_closure(): void
    {
        GateClosure::create([
            'airport_icao' => 'LROP', 'gate_code' => 'A1',
            'from' => CarbonImmutable::now()->subHour(),
            'until' => CarbonImmutable::now()->addDay(),
            'reason' => 'jet bridge repairs',
        ]);

        $this->get('/airports/LROP')
            ->assertOk()
            ->assertSee('data-gate-closed', escape: false)
            ->assertSee('jet bridge repairs')
            ->assertSee('1 closed');
    }

    public function test_a_closure_without_details_reads_as_unknown(): void
    {
        GateClosure::create([
            'airport_icao' => 'LROP', 'gate_code' => 'A1',
            'from' => CarbonImmutable::now()->subHour(), 'until' => null, 'reason' => '',
        ]);

        $this->get('/airports/LROP')
            ->assertOk()
            ->assertSee('data-closure-reason="Unknown"', escape: false)
            ->assertSee('data-closure-until="Unknown"', escape: false);
    }

    public function test_a_gate_reopened_earlier_today_is_not_shown_as_closed(): void
    {
        GateClosure::create([
            'airport_icao' => 'LROP', 'gate_code' => 'A1',
            'from' => CarbonImmutable::now()->subHours(3),
            'until' => CarbonImmutable::now()->subHour(),
            'reason' => 'finished repairs',
        ]);

        $this->get('/airports/LROP')
            ->assertOk()
            ->assertDontSee('data-gate-closed', escape: false)
            ->assertDontSee('finished repairs');
    }

    public function test_unknown_airport_returns_404(): void
    {
        $this->get('/airports/ZZZZ')->assertNotFound();
    }

    public function test_icao_lookup_is_case_insensitive(): void
    {
        $this->get('/airports/lrop')->assertOk()->assertSee('LROP');
    }
}
