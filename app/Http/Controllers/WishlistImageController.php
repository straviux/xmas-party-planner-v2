<?php

namespace App\Http\Controllers;

use App\Models\WishlistItem;
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
 * Public on purpose: the wishlist board itself is a public action,
 * so the photos it shows are not more restricted than the board.
 */
class WishlistImageController
{
    public function __invoke(WishlistItem $item): Response
    {
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
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
