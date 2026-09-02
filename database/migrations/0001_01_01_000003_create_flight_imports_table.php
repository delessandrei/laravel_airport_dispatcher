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

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('flight_imports', function (Blueprint $collection) {
            // One record per airport-day; re-collecting updates it in place.
            $collection->unique(['airport_icao' => 1, 'day' => 1]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->drop('flight_imports');
    }
};
