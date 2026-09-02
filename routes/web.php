<?php

use App\Http\Controllers\AirportController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AirportController::class, 'index'])->name('home');
Route::get('/airports/{icao}', [AirportController::class, 'show'])->name('airports.show');

// Collection is explicit, so simply opening a page never spends OpenSky credits.
Route::post('/airports/{icao}/collect', [AirportController::class, 'collect'])->name('airports.collect');
