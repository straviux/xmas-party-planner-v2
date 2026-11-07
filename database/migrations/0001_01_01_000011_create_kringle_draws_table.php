<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kringle_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            // The Monito Monita roulette: one draw per guest per week.
            // A week's draws form a derangement of the party's guests,
            // built spin by spin (see KringleService::spin).
            $table->date('week_start');
            $table->foreignId('giver_id')->constrained('guests')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('guests')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['party_id', 'week_start', 'giver_id']);
            $table->unique(['party_id', 'week_start', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kringle_draws');
    }
};
