<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAirport;
use App\Models\GateClosure;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Puts a gate back in service.
 *
 * Closures are ended rather than deleted, so the reason a gate was unavailable
 * stays on record. Flights left unallocated will be placed by the next
 * allocation pass, which retries them.
 */
class OpenGate extends Command
{
    use ResolvesAirport;

    protected $signature = 'gates:open
                            {--airport= : ICAO code, defaults to '.self::DEFAULT_AIRPORT.'}
                            {--gate= : Gate code, for example A5}';

    protected $description = 'Reopen a closed gate';

    public function handle(): int
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

        $now = CarbonImmutable::now();

        $active = GateClosure::forAirport($airport->icao)
            ->where('gate_code', $gate)
            ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', $now))
            ->get();

        if ($active->isEmpty()) {
            $this->line("{$airport->icao} gate {$gate} was not closed.");

            return self::SUCCESS;
        }

        foreach ($active as $closure) {
            $closure->forceFill(['until' => $now])->save();
        }

        $this->info(sprintf('%s gate %s reopened; %d closure(s) ended.', $airport->icao, $gate, $active->count()));
        $this->line('  Flights left without a gate will be placed on the next allocation pass.');

        return self::SUCCESS;
    }
}
