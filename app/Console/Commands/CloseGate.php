<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAirport;
use App\Models\Flight;
use App\Models\GateClosure;
use App\Services\Gates\GateAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Takes a gate out of service, permanently or for a period.
 *
 * Flights already standing at that gate during the closure are moved: their
 * allocation is cleared and the allocator is run again for them alone. Any that
 * find nowhere to go are left unallocated with a reason, which the validator
 * and the hourly report will pick up.
 */
class CloseGate extends Command
{
    use ResolvesAirport;

    protected $signature = 'gates:close
                            {--airport= : ICAO code, defaults to '.self::DEFAULT_AIRPORT.'}
                            {--gate= : Gate code, for example A5}
                            {--reason= : Why the gate is out of service}
                            {--from= : Start as Y-m-d or Y-m-d H:i; now when omitted}
                            {--until= : End as Y-m-d or Y-m-d H:i; indefinite when omitted}';

    protected $description = 'Close a gate, moving any flights already allocated to it';

    public function handle(GateAllocationService $allocation): int
    {
        $airport = $this->resolveAirport();

        if (! $airport) {
            return self::FAILURE;
        }

        $gate = strtoupper(trim((string) $this->option('gate')));

        if ($gate === '') {
            $this->error('--gate is required, for example --gate=A5');

            return self::FAILURE;
        }

        $known = collect($airport->gates())->firstWhere('code', $gate);

        if (! $known) {
            $this->error("{$airport->icao} has no gate {$gate}.");

            return self::FAILURE;
        }

        $timezone = $airport->timezone ?: 'UTC';
        $from = $this->parse($this->option('from'), $timezone) ?? CarbonImmutable::now();
        $until = $this->parse($this->option('until'), $timezone);

        if ($until && $until->lessThanOrEqualTo($from)) {
            $this->error('--until must be after --from.');

            return self::FAILURE;
        }

        GateClosure::create([
            'airport_icao' => strtoupper($airport->icao),
            'gate_code' => $gate,
            'from' => $from,
            'until' => $until,
            'reason' => (string) $this->option('reason'),
        ]);

        $this->info(sprintf('%s gate %s closed from %s %s.',
            $airport->icao, $gate, $from->setTimezone($timezone)->format('j M Y H:i'),
            $until ? 'until '.$until->setTimezone($timezone)->format('j M Y H:i') : 'indefinitely'));

        return $this->relocate($airport, $gate, $from, $until, $allocation);
    }

    private function relocate($airport, string $gate, CarbonImmutable $from, ?CarbonImmutable $until, GateAllocationService $allocation): int
    {
        $affected = Flight::where('airport_icao', strtoupper($airport->icao))
            ->where('gate_code', $gate)
            ->where('occupies_until', '>', $from)
            ->when($until, fn ($q) => $q->where('occupies_from', '<', $until))
            ->get();

        if ($affected->isEmpty()) {
            $this->line('  No flights were standing there.');

            return self::SUCCESS;
        }

        $this->line("  {$affected->count()} flights need moving.");

        foreach ($affected as $flight) {
            $flight->forceFill([
                'gate_code' => null, 'gate_terminal' => null,
                'allocation_status' => null, 'allocation_reason' => null,
            ])->save();
        }

        $result = $allocation->allocate($airport, $affected);

        $this->info(sprintf('  Moved %d, could not place %d.',
            count($result['allocations']), count($result['unallocated'])));

        foreach ($result['allocations'] as $moved) {
            $this->line("    {$moved['flight_id']} -> {$moved['gate_code']}");
        }

        return self::SUCCESS;
    }

    private function parse(?string $value, string $timezone): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            $this->error("Could not read the date '{$value}'.");

            exit(self::FAILURE);
        }
    }
}
