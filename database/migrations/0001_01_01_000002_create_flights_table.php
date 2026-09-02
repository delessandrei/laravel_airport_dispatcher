<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Indexes for the flights collection written by the scheduler.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('flights', function (Blueprint $collection) {
            // OpenSky has no flight id, so identity is the aircraft plus the
            // moment it was first tracked. The unique index is what makes a
            // repeated collection run idempotent instead of duplicating rows.
            $collection->unique(['airport_icao' => 1, 'direction' => 1, 'icao24' => 1, 'first_seen' => 1]);

            // The query the flight board runs on every page view.
            $collection->index(['airport_icao' => 1, 'day' => 1, 'direction' => 1, 'first_seen' => 1]);

            // Gate allocation will look for flights that have no gate yet.
            $collection->index(['airport_icao' => 1, 'day' => 1, 'gate_code' => 1]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->drop('flights');
    }
};
