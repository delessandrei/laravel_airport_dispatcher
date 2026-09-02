<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

use Carbon\CarbonImmutable;

/**
 * A period during which a gate cannot be used — the dynamic condition the
 * requirement describes, such as gate B8 being under repair for two days.
 *
 * Either end may be null. A closure with no end runs until someone reopens the
 * gate, which is what `gates:close` without dates produces.
 */
class GateClosure extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'gate_closures';

    protected $fillable = ['airport_icao', 'gate_code', 'from', 'until', 'reason'];

    protected function casts(): array
    {
        return ['from' => 'immutable_datetime', 'until' => 'immutable_datetime'];
    }

    public function scopeForAirport($query, string $icao)
    {
        return $query->where('airport_icao', strtoupper($icao));
    }

    /** Closures that could touch the window, leaving open ends open. */
    public function scopeTouching($query, CarbonImmutable $from, CarbonImmutable $until)
    {
        return $query
            ->where(fn ($q) => $q->whereNull('from')->orWhere('from', '<', $until))
            ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', $from));
    }

    public function isOpenEnded(): bool
    {
        return $this->until === null;
    }

    public function describe(): string
    {
        $from = $this->from?->toDateTimeString() ?? 'always';
        $until = $this->until?->toDateTimeString() ?? 'indefinitely';

        return "{$from} to {$until}";
    }
}
