<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Map coordinates for the business profile.
 *
 * The profile drew a grey box reading "Map — pending embed" because nothing on
 * the model could place a pin: `address`, `city`, `state` and `country` are
 * free text, and a map needs a point.
 *
 * `geocoded_at` records the attempt, not the success. Without it a business
 * whose address cannot be resolved would be looked up again on every single
 * page view, hammering the geocoder for an answer that is not coming.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            // 7 decimal places is ~1cm, far more than a street address needs.
            $table->decimal('latitude', 10, 7)->nullable()->after('country');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('geocoded_at')->nullable()->after('longitude');
            // The address the coordinates belong to, so a later address change
            // invalidates them rather than leaving the pin on the old street.
            $table->string('geocoded_address')->nullable()->after('geocoded_at');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude', 'geocoded_at', 'geocoded_address']);
        });
    }
};
