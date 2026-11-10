<?php

namespace App\Http\Controllers;

use App\Models\WishlistItem;
use App\Services\GuestEntryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves one wishlist item's photo as real image bytes.
 *
 * Photos are stored as WebP data URLs in the database (Hard Rule 7),
 * but payloads never ship those blobs: Serializers::wishlistItem
 * points `image` at this route instead, so the board's JSON stays
 * small no matter how many photo wishes exist, and each photo loads
 * in parallel and caches on its own. The `v` query value is a hash
 * of the stored data, so a replaced photo gets a fresh URL while an
 * unchanged one may be cached forever (immutable).
 *
 * Signed-in guests only, like the board it feeds (2026-11-10):
 * the check is the PIN-verified guest session, and anything else
 * gets the same 404 as a missing photo, so item ids cannot be
 * probed for photos from outside.
 */
class WishlistImageController
{
    public function __invoke(Request $request, WishlistItem $item): Response
    {
        $signedIn = $request->session()->get('pin_verified') === true
            && app(GuestEntryService::class)->sessionGuest($request) !== null;
        if (! $signedIn) {
            abort(404);
        }

        $prefix = 'data:image/webp;base64,';
        $image = $item->image;

        if (! is_string($image) || ! str_starts_with($image, $prefix)) {
            abort(404);
        }

        $bytes = base64_decode(substr($image, strlen($prefix)), true);
        if ($bytes === false || $bytes === '') {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
