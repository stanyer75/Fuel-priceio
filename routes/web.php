<?php

use App\Http\Controllers\JourneyController;
use Illuminate\Support\Facades\Route;

// Searches are throttled per IP so one user can't burn the shared
// openrouteservice daily quota (cached repeats don't touch ORS at all).
Route::get('/', [JourneyController::class, 'show'])->middleware('throttle:30,1')->name('journey');

Route::prefix('api')->group(function () {
    Route::get('/journey', [JourneyController::class, 'api'])->middleware('throttle:30,1');
    Route::get('/status', [JourneyController::class, 'status']);
});
