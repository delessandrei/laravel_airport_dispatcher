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
 * One run of the validator: what it checked, and what it found wrong.
 *
 * A correct allocation can stop being correct later — a gate closed after the
 * fact, a gate removed from the layout, two allocators racing. Recording each
 * run makes that drift visible instead of silent.
 */
class AllocationIssue extends Model
{
    public const CLOSED_GATE = 'closed_gate';

    public const DOUBLE_BOOKED = 'double_booked';

    public const MALFORMED_WINDOW = 'malformed_window';

    protected $connection = 'mongodb';

    protected $collection = 'allocation_issues';

    protected $fillable = [
        'airport_icao', 'checked_at', 'window_from', 'window_until',
        'checked_count', 'issue_count', 'counts', 'issues',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'immutable_datetime',
            'window_from' => 'immutable_datetime',
            'window_until' => 'immutable_datetime',
            'checked_count' => 'integer',
            'issue_count' => 'integer',
        ];
    }

    /** Named away from Eloquent's own isClean(), which means something else. */
    public function hasNoIssues(): bool
    {
        return $this->issue_count === 0;
    }
}
