<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('text')->nullable();
            $table->string('link')->nullable();
            $table->text('notes')->nullable();
            $table->string('kind')->default('text');
            // WebP data URL (Hard Rule 7: WebP only, never AVIF).
            $table->longText('image')->nullable();
            $table->foreignId('reserved_by_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->timestamps();

            $table->index('guest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
