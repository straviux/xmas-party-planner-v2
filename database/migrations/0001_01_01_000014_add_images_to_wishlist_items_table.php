<?php

use App\Models\WishlistItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Up to three photos per wish (owner's ask, 2026-11-10): the
     * photos live in a JSON list of WebP data URLs. The existing
     * single `image` column stays and mirrors the first photo, so
     * anything still reading it sees the cover photo. The backfill
     * seeds the list from the photo each wish already has.
     */
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->json('images')->nullable();
        });

        WishlistItem::query()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->each(function (WishlistItem $item) {
                $item->images = [$item->image];
                $item->save();
            });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn('images');
        });
    }
};
