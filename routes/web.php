<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\WishlistImageController;
use Illuminate\Support\Facades\Route;

// Guest pages (public, public party info only).
Route::get('/', [PageController::class, 'landing'])->name('landing');
Route::get('/enter', [PageController::class, 'enter'])->name('enter');
Route::get('/join/{code}', [PageController::class, 'join'])->name('join');

// Guest session pages (the controller guards redirect to entry / reveal).
Route::get('/reveal', [PageController::class, 'reveal'])->name('reveal');
Route::get('/guest', [PageController::class, 'guestHome'])->name('guest.home');
// My wishes used to be its own page here; it now lives inside the
// Wishlists page as its My wishes view. Old links land there.
Route::redirect('/guest/gifts', '/guest/wishlists?view=mine');
Route::get('/guest/wishlists', [PageController::class, 'guestWishlists'])->name('guest.wishlists');
Route::get('/guest/wishlists/{member}', [PageController::class, 'guestMemberWishlist'])->name('guest.wishlists.member');
Route::get('/guest/vote', [PageController::class, 'guestVote'])->name('guest.vote');
Route::get('/guest/kringle', [PageController::class, 'guestKringle'])->name('guest.kringle');

// Host pages (props carry authed + username only; data comes from the API).
Route::get('/host', [PageController::class, 'hostDashboard'])->name('host.dashboard');
Route::get('/host/guests', [PageController::class, 'hostGuests'])->name('host.guests');
Route::get('/host/polls', [PageController::class, 'hostPolls'])->name('host.polls');
Route::get('/host/settings', [PageController::class, 'hostSettings'])->name('host.settings');

// Wishlist photo bytes. Payloads carry this URL instead of the stored
// data URL, so the board JSON stays small and photos cache per item.
Route::get('/wishlist-items/{item}/image', WishlistImageController::class)->name('wishlist.image');

// Single dispatch API. Deliberately on the web routes so the session and
// CSRF protection apply; action names are a stable contract (spec).
Route::post('/api/{action}', ApiController::class)->name('api.dispatch');
