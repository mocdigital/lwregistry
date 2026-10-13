<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Heal any SBO that could wrongly read as "Verified": rows whose compliance
     * status was set without a corresponding admin confirmation timestamp.
     * A genuinely verified SBO has both the status AND the timestamp; anything
     * else is reset to its provisional default so the derived state is correct.
     */
    public function up(): void
    {
        // Documents marked compliant but never actually confirmed -> back to pending.
        DB::table('sbos')
            ->where('documents_status', 'compliant')
            ->whereNull('documents_compliant_at')
            ->update(['documents_status' => 'pending']);

        // Fees marked paid but never actually confirmed -> back to unpaid.
        DB::table('sbos')
            ->where('payment_status', 'paid')
            ->whereNull('payment_confirmed_at')
            ->update(['payment_status' => 'unpaid']);

        // Backfill nulls left by rows created before these columns existed.
        DB::table('sbos')->whereNull('documents_status')->update(['documents_status' => 'pending']);
        DB::table('sbos')->whereNull('payment_status')->update(['payment_status' => 'unpaid']);
    }

    public function down(): void
    {
        // Data-normalization only; nothing to reverse.
    }
};
