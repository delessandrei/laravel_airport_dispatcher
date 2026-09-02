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
 * Hourly statistics: what moved, which gates carried it, and what could not be
 * placed. Kept as one document per run so the trend is inspectable.
 */
class AllocationReport extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'allocation_reports';

    protected $fillable = ['airport_icao', 'generated_at', 'last_hour', 'day_to_date', 'gates'];

    protected function casts(): array
    {
        return ['generated_at' => 'immutable_datetime'];
    }
}
