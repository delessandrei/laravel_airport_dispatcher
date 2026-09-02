<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * A record that one airport-day has been collected from OpenSky.
 *
 * Without it, a day that genuinely had no traffic would look identical to a day
 * nobody has collected, and the board would pay for the same empty answer on
 * every page view. This is what makes "already fetched, and the answer was
 * nothing" a state the application can tell apart.
 */
class FlightImport extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'flight_imports';

    protected $fillable = ['airport_icao', 'day', 'flights_count', 'imported_at', 'directions'];

    protected function casts(): array
    {
        return [
            'flights_count' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * True when both halves of the day have been collected.
     *
     * Records written before directions were tracked have no such field; those
     * always covered both halves, so a missing value counts as complete.
     */
    public function isComplete(): bool
    {
        $directions = $this->directions;

        if ($directions === null) {
            return true;
        }

        return in_array(Flight::DIRECTION_ARRIVAL, $directions, true)
            && in_array(Flight::DIRECTION_DEPARTURE, $directions, true);
    }

    public function scopeForDay($query, string $icao, string $day)
    {
        return $query->where('airport_icao', strtoupper($icao))->where('day', $day);
    }
}
