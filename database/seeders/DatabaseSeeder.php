<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\EspeesTestAccount;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'test@example.com',
        ]);

        // Seed global states/cities when a dataset file is available. Skipped
        // silently otherwise so fresh setups without the file don't fail.
        if (is_file(database_path('data/countries+states+cities.json'))
            || is_file(database_path('data/countries+states.json'))) {
            $this->call(WorldStatesCitiesSeeder::class);
        }

        // Starting SBO fee schedule (placeholder amounts; MOC edits via admin UI).
        $this->call(SboFeeScheduleSeeder::class);

        // Church zones (with generated referral codes) for the join form + admin.
        $this->call(ZoneSeeder::class);

        // Explicitly opt-in, non-production checkout fixture.
        if (EspeesTestAccount::enabled()) {
            $this->call(EspeesTestAccountSeeder::class);
        }
    }
}
