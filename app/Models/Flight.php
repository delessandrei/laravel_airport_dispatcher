<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Models;

use App\Services\OpenSky\Airlines;
use MongoDB\Laravel\Eloquent\Model;

/**
 * One observed flight leg, stored against the airport it was collected for.
 *
 * The same physical leg is stored twice — once as a departure at its origin and
 * once as an arrival at its destination — because a dispatcher only ever works
 * from one airport's point of view, and each side gets its own gate.
 *
 * `gate_code` is deliberately left null on import. Filling it is the allocation
 * step, which runs separately from collection.
 */
class Flight extends Model
{
    public const DIRECTION_ARRIVAL = 'arrival';

    public const DIRECTION_DEPARTURE = 'departure';

    public const ALLOCATED = 'allocated';

    public const UNALLOCATED = 'unallocated';

    protected $connection = 'mongodb';

    protected $collection = 'flights';

    /**
     * Distances are metres from the airport reference point at the moment the
     * aircraft was first or last tracked. Candidate counts are how many airports
     * OpenSky weighed before settling on one.
     *
     * Measured against real Frankfurt traffic, the horizontal distance separates
     * good attributions from guesses — correct destinations sat between 362 and
     * 3,447 m, while misattributions started around 10,000 m. The vertical
     * distance does not: coverage usually ends at cruise altitude, so genuine
     * destinations show 9,000-15,000 m there. Any threshold belongs in the
     * allocation step, with these raw values as its input.
     */
    protected $fillable = [
        'airport_icao', 'direction', 'day', 'icao24', 'callsign',
        'departure_airport', 'arrival_airport', 'first_seen', 'last_seen',
        'departure_horiz_distance', 'departure_vert_distance',
        'arrival_horiz_distance', 'arrival_vert_distance',
        'departure_candidates', 'arrival_candidates',
        'gate_code', 'gate_terminal', 'occupies_from', 'occupies_until',
        'allocation_status', 'allocation_reason', 'source',
    ];

    protected function casts(): array
    {
        return [
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
            'departure_horiz_distance' => 'integer',
            'departure_vert_distance' => 'integer',
            'arrival_horiz_distance' => 'integer',
            'arrival_vert_distance' => 'integer',
            'departure_candidates' => 'integer',
            'arrival_candidates' => 'integer',
            'occupies_from' => 'immutable_datetime',
            'occupies_until' => 'immutable_datetime',
        ];
    }

    /**
     * The natural key of a leg as OpenSky reports it. There is no flight id in
     * the API, so identity is the aircraft plus the moment it was first seen.
     *
     * @return array<string, mixed>
     */
    public function identity(): array
    {
        return [
            'airport_icao' => $this->airport_icao,
            'direction' => $this->direction,
            'icao24' => $this->icao24,
            'first_seen' => $this->first_seen,
        ];
    }

    /**
     * Flights without a gate. Ones that failed to be placed before are included
     * on purpose: a closure lifted or a gate freed should let them through on
     * the next pass, rather than leaving them stuck.
     */
    public function scopeNeedingAllocation($query, string $icao)
    {
        return $query->where('airport_icao', strtoupper($icao))->whereNull('gate_code');
    }

    /**
     * Allocations whose window overlaps [from, until).
     *
     * This is the query the validator runs, and it is deliberately expressed on
     * the window rather than on the anchor time: it stays correct when the
     * occupancy duration or its offset changes.
     */
    public function scopeOverlapping($query, string $icao, $from, $until)
    {
        return $query->where('airport_icao', strtoupper($icao))
            ->whereNotNull('gate_code')
            ->where('occupies_from', '<', $until)
            ->where('occupies_until', '>', $from);
    }

    public function scopeForBoard($query, string $icao, string $day, string $direction)
    {
        return $query->where('airport_icao', strtoupper($icao))
            ->where('day', $day)
            ->where('direction', $direction);
    }

    /** The airport at the other end of the leg, seen from the tracked airport. */
    public function counterpart(): ?string
    {
        return $this->direction === self::DIRECTION_ARRIVAL
            ? $this->departure_airport
            : $this->arrival_airport;
    }

    /** Airline name resolved from the callsign prefix, when it is known. */
    public function carrier(): ?string
    {
        return Airlines::nameFor($this->callsign);
    }

    /** Block time, from first to last radar contact. */
    public function duration(): string
    {
        if (! $this->first_seen || ! $this->last_seen) {
            return '—';
        }

        $minutes = (int) $this->first_seen->diffInMinutes($this->last_seen);

        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }

    /** The time this flight touches the airport it is filed under. */
    public function boardTime()
    {
        return $this->direction === self::DIRECTION_ARRIVAL ? $this->last_seen : $this->first_seen;
    }

    public function isAllocated(): bool
    {
        return filled($this->gate_code);
    }
}
