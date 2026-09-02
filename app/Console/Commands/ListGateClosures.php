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

class ListGateClosures extends Command
{
    use ResolvesAirport;

    protected $signature = 'gates:closures
                            {--airport= : ICAO code, defaults to '.self::DEFAULT_AIRPORT.'}
                            {--all : Include closures that have already ended}';

    protected $description = 'List gate closures';

    public function handle(): int
    {
        $airport = $this->resolveAirport();

        if (! $airport) {
            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $timezone = $airport->timezone ?: 'UTC';

        $closures = GateClosure::forAirport($airport->icao)
            ->when(! $this->option('all'), fn ($q) => $q->where(
                fn ($w) => $w->whereNull('until')->orWhere('until', '>', $now),
            ))
            ->orderBy('gate_code')
            ->get();

        if ($closures->isEmpty()) {
            $this->line($airport->icao.': no '.($this->option('all') ? '' : 'active ').'closures.');

            return self::SUCCESS;
        }

        $this->table(
            ['Gate', 'From', 'Until', 'Reason'],
            $closures->map(fn (GateClosure $c) => [
                $c->gate_code,
                $c->from?->setTimezone($timezone)->format('j M Y H:i') ?? 'always',
                $c->until?->setTimezone($timezone)->format('j M Y H:i') ?? 'indefinite',
                $c->reason ?: '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
