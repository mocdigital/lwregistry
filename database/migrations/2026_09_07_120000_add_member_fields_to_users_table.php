<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Registration now creates a member account BEFORE any business exists, so the
 * Loveworld-member details it collects (which used to live only on the first
 * SBO) need a home on the user. When the member later adds a business, these are
 * copied onto the SBO — so a member enters their church details once and every
 * business they register inherits them.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('country')->nullable()->after('phone');
            $table->string('residential_address')->nullable()->after('country');
            $table->string('church_zone')->nullable()->after('residential_address');
            $table->foreignId('zone_id')->nullable()->after('church_zone')
                ->constrained('zones')->nullOnDelete();
            $table->string('local_assembly')->nullable()->after('zone_id');
            $table->unsignedInteger('years_as_member')->nullable()->after('local_assembly');
            $table->string('zonal_pastor')->nullable()->after('years_as_member');
            $table->string('local_pastor')->nullable()->after('zonal_pastor');
            // The referral code that attributed this sign-up, if any.
            $table->string('referred_via_code')->nullable()->after('local_pastor');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('zone_id');
            $table->dropColumn([
                'country',
                'residential_address',
                'church_zone',
                'local_assembly',
                'years_as_member',
                'zonal_pastor',
                'local_pastor',
                'referred_via_code',
            ]);
        });
    }
};
