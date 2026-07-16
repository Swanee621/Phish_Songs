<?php

use App\Http\Controllers\PhishNetExamplesController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::get('/jam-charts', [PhishNetExamplesController::class, 'jamChartExplorer'])->name('jam-charts');
Route::get('/recent-setlists', [PhishNetExamplesController::class, 'recentSetlists'])->name('recent-setlists');
Route::get('/setlist-browser', [PhishNetExamplesController::class, 'setlistBrowser'])->name('setlist-browser');
Route::get('/venues', [PhishNetExamplesController::class, 'venueExplorer'])->name('venues');

Route::prefix('data')->name('data.')->group(function () {
    Route::get('/jam-charts', [PhishNetExamplesController::class, 'jamCharts'])->name('jam-charts');
    Route::get('/jam-charts/{slug}', [PhishNetExamplesController::class, 'jamChart'])
        ->where('slug', '[a-z0-9-]+')
        ->name('jam-chart');
    Route::get('/recent-setlists', [PhishNetExamplesController::class, 'currentYearSetlists'])->name('recent-setlists');
    Route::get('/setlists/{showdate}', [PhishNetExamplesController::class, 'setlistForDate'])
        ->where('showdate', '\d{4}-\d{2}-\d{2}')
        ->name('setlist');
    Route::get('/setlists/year/{year}', [PhishNetExamplesController::class, 'setlistsForYear'])
        ->where('year', '[0-9]{4}')
        ->name('setlists-for-year');
    Route::get('/show-years', [PhishNetExamplesController::class, 'showYears'])->name('show-years');
    Route::get('/venues', [PhishNetExamplesController::class, 'venues'])->name('venues');
    Route::get('/venues/{venue}/shows', [PhishNetExamplesController::class, 'venueShows'])
        ->where('venue', '[0-9]+')
        ->name('venue-shows');
});

require __DIR__.'/settings.php';
