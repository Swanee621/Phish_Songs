<?php

use App\Http\Controllers\PhishNetExamplesController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PhishNetExamplesController::class, 'songChecker'])->name('home');
Route::get('/recent-setlists', [PhishNetExamplesController::class, 'recentSetlists'])->name('recent-setlists');
Route::get('/setlist-browser', [PhishNetExamplesController::class, 'setlistBrowser'])->name('setlist-browser');

Route::prefix('data')->name('data.')->group(function () {
    Route::get('/recent-setlists', [PhishNetExamplesController::class, 'currentYearSetlists'])->name('recent-setlists');
    Route::get('/setlists/{showdate}', [PhishNetExamplesController::class, 'setlistForDate'])
        ->where('showdate', '\d{4}-\d{2}-\d{2}')
        ->name('setlist');
    Route::get('/setlists/year/{year}', [PhishNetExamplesController::class, 'setlistsForYear'])
        ->where('year', '[0-9]{4}')
        ->name('setlists-for-year');
    Route::get('/show-years', [PhishNetExamplesController::class, 'showYears'])->name('show-years');
    Route::get('/songs', [PhishNetExamplesController::class, 'songs'])->name('songs');
});

require __DIR__.'/settings.php';
