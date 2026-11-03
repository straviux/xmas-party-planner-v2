<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->string('display');
            $table->string('nickname')->nullable();
            $table->json('aliases')->nullable();
            $table->string('pin_hash')->nullable();
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->foreign('recipient_id')->references('id')->on('guests')->nullOnDelete();
            $table->boolean('claimed')->default(false);
            $table->timestamp('claimed_at')->nullable();
            $table->boolean('revealed')->default(false);
            $table->timestamps();

            $table->index('party_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
