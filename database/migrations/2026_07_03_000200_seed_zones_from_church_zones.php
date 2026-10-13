<?php

declare(strict_types=1);

use App\Models\Zone;
use Database\Seeders\ZoneSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Populate the `zones` table on deploy so the admin Zone Referrals list is never
 * empty. The zone list is otherwise only loaded by ZoneSeeder (a manual step);
 * running it here means a plain `php artisan migrate` seeds the 184 zones (each
 * with a generated referral code) automatically.
 *
 * Idempotent: ZoneSeeder matches on name via firstOrCreate, so this is a no-op
 * for zones that already exist and never duplicates or overwrites them.
 */
return new class() extends Migration
{
    public function up(): void
    {
        (new ZoneSeeder())->run();
    }

    public function down(): void
    {
        // Only remove zones that have never been used or contacted, so we never
        // delete admin-entered pastor contacts or zones with registrations.
        Zone::query()
            ->doesntHave('sbos')
            ->whereNull('pastor_email')
            ->whereNull('sent_at')
            ->delete();
    }
};
