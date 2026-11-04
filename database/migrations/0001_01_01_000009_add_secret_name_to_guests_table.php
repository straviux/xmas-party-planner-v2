<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            // The guest's self-chosen codename. Nullable: guests who have
            // not picked one yet simply have no secret name, and reveals
            // naming them return null until they do.
            $table->string('secret_name', 60)->nullable()->after('nickname');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('secret_name');
        });
    }
};
