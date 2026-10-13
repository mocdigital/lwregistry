<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point reviews at the listings people can actually see.
 *
 * `reviews.business_id` references `businesses`, a legacy table that holds zero
 * rows — the registry is `sbos`, and every profile page renders one. So
 * `POST /business/{slug}/reviews` bound a Business by a slug that only ever
 * belongs to an Sbo, and the route could not match anything: "Submit Review"
 * was a button whose endpoint had no reachable subject.
 *
 * `business_id` stays and becomes nullable rather than being dropped. It is a
 * foreign key with rows referencing it in other installs, and removing a column
 * to fix a routing mistake destroys data to save a schema tidy-up.
 *
 * The unique pair is the rule the controller already applies in PHP — one
 * review per member per listing, updated rather than duplicated. Expressing it
 * in the schema is what stops two concurrent submissions from both passing the
 * "does one exist" check and writing two rows.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('sbo_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

            $table->unique(['sbo_id', 'user_id'], 'reviews_sbo_user_unique');
        });

        /*
         * Nullable now: a review belongs to one or the other, and every row
         * written from here on belongs to an Sbo.
         */
        Schema::table('reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('business_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique('reviews_sbo_user_unique');
            $table->dropConstrainedForeignId('sbo_id');
        });
    }
};
