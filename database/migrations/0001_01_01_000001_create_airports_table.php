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
 * Indexes for the airports collection. Terminals and gates are embedded
 * documents inside each airport, so no separate collections are needed.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('airports', function (Blueprint $collection) {
            // ICAO is the key OpenSky uses and the route key of the model.
            $collection->unique('icao');
            $collection->index('iata');
            // The home page lists airports one country at a time.
            $collection->index(['country_code' => 1, 'city' => 1]);
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->drop('airports');
    }
};
