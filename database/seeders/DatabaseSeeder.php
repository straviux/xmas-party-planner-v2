<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Creates the initial host account from ADMIN_USERNAME / ADMIN_PASSWORD.
     * firstOrCreate on the username: re-seeding never resets a password the
     * host changed after first sign-in (spec).
     */
    public function run(): void
    {
        $username = env('ADMIN_USERNAME', 'host') ?: 'host';
        $password = env('ADMIN_PASSWORD', 'changeme') ?: 'changeme';

        User::firstOrCreate(
            ['username' => $username],
            ['password' => Hash::make($password)],
        );
    }
}
