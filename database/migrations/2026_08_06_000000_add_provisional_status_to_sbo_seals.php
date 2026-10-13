<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A seal is now issued at payment and confirmed at full verification.
 *
 * Previously `SboSealIssuer::issueFor()` refused unless the SBO was fully
 * verified, so a paid member had nothing to download while compliance
 * reviewed their documents. The seal is issued as soon as the fee is settled,
 * marked `provisional`, and re-rendered as `verified` when approval and
 * document compliance land.
 *
 * `verified_at` is separate from `issued_at` on purpose: the issue date is
 * when the registration was paid for, and that should not move when the badge
 * is later upgraded.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbo_seals', function (Blueprint $table): void {
            $table->string('status')->default('provisional')->after('image_path')->index();
            $table->timestamp('verified_at')->nullable()->after('issued_at');
        });

        /*
         * Every seal that exists today was issued under the old rule, which
         * required full verification — so they are all verified, and backdating
         * `verified_at` to their issue date is accurate rather than a guess.
         */
        DB::table('sbo_seals')->update([
            'status' => 'verified',
            'verified_at' => DB::raw('issued_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sbo_seals', function (Blueprint $table): void {
            $table->dropColumn(['status', 'verified_at']);
        });
    }
};
