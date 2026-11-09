<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poll_options', function (Blueprint $table) {
            // Optional photo for the option (a colour swatch, an outfit
            // shot), stored as a WebP data URL exactly like wishlist
            // images (Hard Rule 7). Nullable: most options stay text
            // only.
            $table->longText('image')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('poll_options', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
