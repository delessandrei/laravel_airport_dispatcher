<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightImport;
use App\Services\OpenSky\FlightImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlightImportTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    protected array $arrivalResponse = [];

    /** @var array<int, array<string, mixed>> */
    protected array $departureResponse = [];

    protected function setUp(): void
    {
        parent::setUp();

        Airport::truncate();
        Flight::truncate();
        FlightImport::truncate();

        Airport::create([
            'icao' => 'EDDF', 'iata' => 'FRA', 'name' => 'Frankfurt am Main',
            'city' => 'Frankfurt', 'country_code' => 'DE', 'country_name' => 'Germany',
            'timezone' => 'Europe/Berlin', 'latitude' => 50.0379, 'longitude' => 8.5622,
            'terminals' => [],
        ]);

        // No request leaves the test suite; the allowance is never touched.
        config([
            'services.opensky.client_id' => 'test-client',
            'services.opensky.client_secret' => 'test-secret',
        ]);

        // A single catch-all closure, so nothing can slip through to the real
        // API and spend credits. Tests set the response arrays they need.
        Http::preventStrayRequests();

        Http::fake(function ($request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'openid-connect/token') => Http::response(['access_token' => 'fake', 'expires_in' => 1800]),
                str_contains($url, '/flights/arrival') => Http::response($this->arrivalResponse),
                str_contains($url, '/flights/departure') => Http::response($this->departureResponse),
                default => Http::response([], 404),
            };
        });
    }

    public function test_importing_one_direction_records_only_that_direction(): void
    {
        $this->importer()->import($this->airport(), $this->day(), Flight::DIRECTION_ARRIVAL);

        $import = FlightImport::forDay('EDDF', $this->day()->toDateString())->first();

        $this->assertSame([Flight::DIRECTION_ARRIVAL], $import->directions);
        $this->assertFalse($import->isComplete(), 'One half of a day is not a complete day.');
    }

    public function test_directions_accumulate_across_runs(): void
    {
        $this->importer()->import($this->airport(), $this->day(), Flight::DIRECTION_ARRIVAL);
        $this->importer()->import($this->airport(), $this->day(), Flight::DIRECTION_DEPARTURE);

        $import = FlightImport::forDay('EDDF', $this->day()->toDateString())->first();

        $this->assertTrue($import->isComplete(), 'Two halves collected separately make a whole day.');
    }

    public function test_a_legacy_record_without_directions_is_not_downgraded(): void
    {
        // Written before directions were tracked: it covered both halves.
        FlightImport::create([
            'airport_icao' => 'EDDF',
            'day' => $this->day()->toDateString(),
            'flights_count' => 121,
            'imported_at' => now(),
        ]);

        $this->importer()->import($this->airport(), $this->day(), Flight::DIRECTION_ARRIVAL);

        $import = FlightImport::forDay('EDDF', $this->day()->toDateString())->first();

        $this->assertTrue(
            $import->isComplete(),
            'Re-collecting one direction must not mark an already complete day as partial.',
        );
    }

    public function test_importing_without_a_direction_collects_both(): void
    {
        $this->importer()->import($this->airport(), $this->day());

        $this->assertTrue(FlightImport::forDay('EDDF', $this->day()->toDateString())->first()->isComplete());
    }

    public function test_all_opensky_fields_are_stored(): void
    {
        // A real record, taken verbatim from a /flights/departure response.
        $this->departureResponse = [[
                'icao24' => '3c64a6',
                'firstSeen' => 1788206990,
                'estDepartureAirport' => 'EDDF',
                'lastSeen' => 1788217606,
                'estArrivalAirport' => null,
                'callsign' => 'DLH1330 ',
                'estDepartureAirportHorizDistance' => 4677,
                'estDepartureAirportVertDistance' => 155,
                'estArrivalAirportHorizDistance' => null,
                'estArrivalAirportVertDistance' => null,
                'departureAirportCandidatesCount' => 149,
                'arrivalAirportCandidatesCount' => 0,
        ]];

        $this->importer()->import($this->airport(), $this->day(), Flight::DIRECTION_DEPARTURE);

        $flight = Flight::where('callsign', 'DLH1330')->firstOrFail();

        $this->assertSame(4677, $flight->departure_horiz_distance);
        $this->assertSame(155, $flight->departure_vert_distance);
        $this->assertNull($flight->arrival_horiz_distance);
        $this->assertNull($flight->arrival_vert_distance);
        $this->assertSame(149, $flight->departure_candidates);
        $this->assertSame(0, $flight->arrival_candidates);

    }

    public function test_opening_a_page_never_collects_on_its_own(): void
    {
        $this->get('/airports/EDDF?date='.$this->day()->toDateString())
            ->assertOk()
            ->assertSee('has not been collected yet')
            ->assertSee('Collect from OpenSky');

        Http::assertNothingSent();
    }

    public function test_the_pull_button_collects_and_reports_back(): void
    {

        $this->departureResponse = [[
            'icao24' => '3c64a6', 'firstSeen' => 1788206990, 'lastSeen' => 1788217606,
            'estDepartureAirport' => 'EDDF', 'estArrivalAirport' => null, 'callsign' => 'DLH1330 ',
        ]];

        $this->post('/airports/EDDF/collect', ['date' => $this->day()->toDateString()])
            ->assertRedirect()
            ->assertSessionHas('status', 'Collected 1 flights from OpenSky.');

        $this->assertSame(1, Flight::where('callsign', 'DLH1330')->count());
        $this->assertTrue(
            FlightImport::forDay('EDDF', $this->day()->toDateString())->exists(),
            'The day posted in the form must be the day collected, not today.',
        );
        $this->assertSame($this->day()->toDateString(), Flight::where('callsign', 'DLH1330')->first()->day);
    }

    public function test_a_collected_day_is_then_served_without_touching_the_api(): void
    {
        $this->post('/airports/EDDF/collect', ['date' => $this->day()->toDateString()]);

        Http::fake();   // any request from here on would be a failure

        $this->get('/airports/EDDF?date='.$this->day()->toDateString())
            ->assertOk()
            ->assertDontSee('has not been collected yet');

        Http::assertNothingSent();
    }

    private function importer(): FlightImporter
    {
        return app(FlightImporter::class);
    }

    private function airport(): Airport
    {
        return Airport::where('icao', 'EDDF')->firstOrFail();
    }

    private function day(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 8, 31, 0, 0, 0, 'Europe/Berlin');
    }
}
