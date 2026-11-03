<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

// Guest pages (public, public party info only).
Route::get('/', [PageController::class, 'landing'])->name('landing');
Route::get('/enter', [PageController::class, 'enter'])->name('enter');
Route::get('/join/{code}', [PageController::class, 'join'])->name('join');

// Host pages (props carry authed + username only; data comes from the API).
Route::get('/host', [PageController::class, 'hostDashboard'])->name('host.dashboard');
Route::get('/host/guests', [PageController::class, 'hostGuests'])->name('host.guests');
Route::get('/host/polls', [PageController::class, 'hostPolls'])->name('host.polls');
Route::get('/host/settings', [PageController::class, 'hostSettings'])->name('host.settings');

// Single dispatch API. Deliberately on the web routes so the session and
// CSRF protection apply; action names are a stable contract (spec).
Route::post('/api/{action}', ApiController::class)->name('api.dispatch');
