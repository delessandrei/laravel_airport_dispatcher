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
 * Collections and indexes for gate allocation.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('gate_closures', function (Blueprint $collection) {
            // The allocator asks "is this gate closed during this window?" for
            // every gate it considers, so this index is on the hot path.
            $collection->index(['airport_icao' => 1, 'gate_code' => 1, 'from' => 1, 'until' => 1]);
        });

        Schema::connection('mongodb')->create('allocation_issues', function (Blueprint $collection) {
            $collection->index(['airport_icao' => 1, 'checked_at' => -1]);
        });

        Schema::connection('mongodb')->create('allocation_reports', function (Blueprint $collection) {
            $collection->index(['airport_icao' => 1, 'generated_at' => -1]);
        });

        Schema::connection('mongodb')->table('flights', function (Blueprint $collection) {
            // Overlap detection, both when allocating and when validating.
            $collection->index(['airport_icao' => 1, 'occupies_from' => 1, 'occupies_until' => 1]);
            // "What still needs a gate?"
            $collection->index(['airport_icao' => 1, 'allocation_status' => 1, 'gate_code' => 1]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->drop('gate_closures');
        Schema::connection('mongodb')->drop('allocation_issues');
        Schema::connection('mongodb')->drop('allocation_reports');
    }
};
