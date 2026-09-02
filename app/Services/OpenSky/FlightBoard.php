<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Services\OpenSky;

use App\Models\Flight;
use Carbon\CarbonInterface;

/**
 * The arrivals and departures shown for one airport on one day.
 *
 * For the current day the lists are trimmed to a window around now — the last
 * few movements and the next few — the way a terminal display board works. For
 * a completed day the whole day is carried, and $isWindowed is false.
 */
final readonly class FlightBoard
{
    /**
     * @param  array<int, Flight>  $arrivals     possibly windowed
     * @param  array<int, Flight>  $departures   possibly windowed
     * @param  int  $totalArrivals              before windowing
     * @param  int  $totalDepartures            before windowing
     * @param  CarbonInterface|null  $pivot     the "now" the window is centred on
     */
    public function __construct(
        public array $arrivals,
        public array $departures,
        public bool $isDemo,
        public ?string $notice = null,
        public ?int $creditsRemaining = null,
        public ?CarbonInterface $importedAt = null,
        public int $totalArrivals = 0,
        public int $totalDepartures = 0,
        public bool $isWindowed = false,
        public ?CarbonInterface $pivot = null,
        public string $sort = 'time',
        public string $sortDirection = 'desc',
    ) {}

    public function total(): int
    {
        return $this->totalArrivals + $this->totalDepartures;
    }

    /** True when the list shown is a trimmed view of a longer day. */
    public function isTrimmed(string $direction): bool
    {
        return $direction === Flight::DIRECTION_ARRIVAL
            ? count($this->arrivals) < $this->totalArrivals
            : count($this->departures) < $this->totalDepartures;
    }
}
