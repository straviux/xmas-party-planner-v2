<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kringle_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            // Monday of the theme's week (Asia/Manila), one theme per
            // party per week, generated lazily and never changed after
            // (except by the host regenerate, which edits this row).
            $table->date('week_start');
            $table->string('theme', 80);
            $table->timestamps();

            $table->unique(['party_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kringle_themes');
    }
};
