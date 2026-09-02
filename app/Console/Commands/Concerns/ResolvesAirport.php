<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Console\Commands\Concerns;

use App\Models\Airport;

/**
 * Shared --airport handling. Frankfurt is the default across every gate
 * command, matching the collector; the option exists for manual work.
 */
trait ResolvesAirport
{
    private const DEFAULT_AIRPORT = 'EDDF';

    protected function resolveAirport(): ?Airport
    {
        $icao = strtoupper((string) ($this->option('airport') ?: self::DEFAULT_AIRPORT));

        $airport = Airport::where('icao', $icao)->first();

        if (! $airport) {
            $this->error("{$icao} is not seeded. Run: php artisan db:seed");

            return null;
        }

        return $airport;
    }
}
