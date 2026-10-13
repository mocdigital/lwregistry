<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a branch address hold its own resolved point.
 *
 * `sbo_addresses` was created with `latitude` and `longitude` and nothing to
 * say WHICH address they belong to. `Geocoder` decides whether a cached point
 * is still good by comparing the address it resolved against the address the
 * record holds now — that is what `geocoded_address` on `sbos` is for — and
 * without the pair a branch pin can never be invalidated. Move an office from
 * Lagos to Accra and the map keeps the Lagos coordinates forever, silently,
 * because nothing can tell that they are stale.
 *
 * `geocoded_at` is the other half: it is how a lookup that FAILED is retried
 * later instead of being attempted on every page view. A rural address
 * OpenStreetMap has never heard of would otherwise hit Nominatim once per
 * visitor, which is both slow and a breach of their usage policy.
 *
 * Needed now because the business profile lists every address a business
 * trades from, each with its own map link — until this, only the primary
 * address (which lives on `sbos`) could resolve to a pin.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbo_addresses', function (Blueprint $table): void {
            // The exact string that produced the stored coordinates. Null on
            // every existing row, which reads as "never resolved" and is
            // correct: nothing has geocoded these yet.
            $table->string('geocoded_address')->nullable()->after('longitude');

            // When the attempt was made, successful or not.
            $table->timestamp('geocoded_at')->nullable()->after('geocoded_address');
        });
    }

    public function down(): void
    {
        Schema::table('sbo_addresses', function (Blueprint $table): void {
            $table->dropColumn(['geocoded_address', 'geocoded_at']);
        });
    }
};
