<?php

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AppController::class, 'songChecker'])->name('home');
Route::get('/recent-setlists', [AppController::class, 'recentSetlists'])->name('recent-setlists');
Route::get('/setlist-browser', [AppController::class, 'setlistBrowser'])->name('setlist-browser');

Route::prefix('data')->name('data.')->group(function () {
    Route::get('/recent-setlists', [AppController::class, 'currentYearSetlists'])->name('recent-setlists');
    Route::get('/setlists/{showdate}', [AppController::class, 'setlistForDate'])
        ->where('showdate', '\d{4}-\d{2}-\d{2}')
        ->name('setlist');
    Route::get('/setlists/year/{year}', [AppController::class, 'setlistsForYear'])
        ->where('year', '[0-9]{4}')
        ->name('setlists-for-year');
    Route::get('/show-years', [AppController::class, 'showYears'])->name('show-years');
    Route::get('/songs', [AppController::class, 'songs'])->name('songs');
    Route::get('/songs/{slug}/performances', [AppController::class, 'songPerformances'])
        ->where('slug', '[a-z0-9-]+')
        ->name('song-performances');
    Route::get('/songs/{slug}/performances/tour/{tour}', [AppController::class, 'songTourPerformances'])
        ->where('slug', '[a-z0-9-]+')
        ->where('tour', '[0-9]+')
        ->name('song-tour-performances');
    Route::get('/live', [AppController::class, 'liveStatus'])->name('live');
});
