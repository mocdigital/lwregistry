<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            // Resolve the SBO's country to a real Country row so config
            // (fees, later documents) can be scoped by country_id — the same
            // shape Business already uses. The free-text `country` name is kept
            // for display/back-compat; this FK is the structured source of truth.
            $table->foreignId('country_id')->nullable()->after('country')
                ->constrained('countries')->nullOnDelete();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('country_id');
        });
    }

    /**
     * Backfill country_id from the existing free-text `country` name. The name
     * was validated `exists:countries,name` on capture, so an exact match is
     * expected; unmatched rows stay null and fall back to the global default.
     */
    private function backfill(): void
    {
        $idByName = DB::table('countries')->pluck('id', 'name');

        foreach (DB::table('sbos')->select('id', 'country')->get() as $sbo) {
            if (null !== $sbo->country && isset($idByName[$sbo->country])) {
                DB::table('sbos')->where('id', $sbo->id)
                    ->update(['country_id' => $idByName[$sbo->country]]);
            }
        }
    }
};
