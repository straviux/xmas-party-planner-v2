<?php

use App\Models\Party;
use App\Support\TempSecretName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Temporary secret names (2026-11-10): the flag marks a codename
     * the app assigned for the guest over one they picked themselves.
     * The same run backfills a temporary name onto every guest who has
     * none, so existing parties are covered the moment this deploys.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->boolean('secret_name_temp')->default(false);
        });

        foreach (Party::query()->get() as $party) {
            TempSecretName::backfillParty($party);
        }
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('secret_name_temp');
        });
    }
};
