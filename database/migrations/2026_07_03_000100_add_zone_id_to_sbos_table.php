<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link SBOs to a structured zone so registrations can be attributed to a source
 * zone (for the referral-link feature and the momentum dashboard). The free-text
 * `church_zone` name is kept for display/back-compat; `zone_id` is the FK.
 * `referred_via_code` records the raw referral code the SBO came through — an
 * audit trail that survives even if a zone is renamed or its code regenerated.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->foreignId('zone_id')->nullable()->after('church_zone')
                ->constrained('zones')->nullOnDelete();
            $table->string('referred_via_code')->nullable()->after('zone_id');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('zone_id');
            $table->dropColumn('referred_via_code');
        });
    }

    /**
     * Backfill zone_id from the existing free-text `church_zone` name. The name
     * was validated against the fixed zone list on capture, so an exact match is
     * expected once zones are seeded. If the `zones` table is empty at migration
     * time (seeding runs separately), rows simply stay null and are resolved
     * later when a zone is chosen or reseeded.
     */
    private function backfill(): void
    {
        $idByName = DB::table('zones')->pluck('id', 'name');

        if ($idByName->isEmpty()) {
            return;
        }

        foreach (DB::table('sbos')->select('id', 'church_zone')->get() as $sbo) {
            if (null !== $sbo->church_zone && isset($idByName[$sbo->church_zone])) {
                DB::table('sbos')->where('id', $sbo->id)
                    ->update(['zone_id' => $idByName[$sbo->church_zone]]);
            }
        }
    }
};
