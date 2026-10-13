<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A business can trade from more than one place.
 *
 * `sbos` carries one address, so a business with offices in Lagos, Accra and
 * London could record exactly one of them — while the card design draws a stack
 * of country flags, which had to be faked from a rotating list of assets
 * because there was no per-business data behind it.
 *
 * The columns on `sbos` stay. They are the PRIMARY address: registration writes
 * them, the geocoder caches its point on them, and every existing query filters
 * on `sbos.country`. Rewriting all of that to read a join is a much larger
 * change than the feature needs, so the first row here mirrors those columns and
 * the rest are additions.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sbo_id')->constrained()->cascadeOnDelete();

            /*
             * What the owner calls this place — "Head Office", "Accra Branch".
             * Optional: a business with two addresses and no names for them is
             * still better served than one with a single address.
             */
            $table->string('label')->nullable();

            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            /*
             * Resolved from `country` on save. This is what the flag reads:
             * matching a free-text country name to an image at render time
             * would have to happen on every card.
             */
            $table->string('country_code', 2)->nullable();

            // Cached by the geocoder, same as the columns on `sbos`.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            /*
             * Exactly one primary per listing — the address the profile header
             * and the map use. Enforced in SboAddress rather than by a unique
             * index, because "one row where is_primary is true" is not
             * something a unique index can express portably.
             */
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // The card's flag stack is a distinct-country query per listing.
            $table->index(['sbo_id', 'position']);
            $table->index(['sbo_id', 'country_code']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_addresses');
    }

    /**
     * Every existing listing gets its current address as row one.
     *
     * Without this, opening the editor on an established listing would show an
     * empty address list beside a profile that plainly has an address — and the
     * first save would look like it had wiped it.
     */
    private function backfill(): void
    {
        $codes = DB::table('countries')
            ->whereNotNull('iso2')
            ->pluck('iso2', 'name')
            ->mapWithKeys(fn (string $iso2, string $name): array => [
                mb_strtolower(mb_trim($name)) => mb_strtoupper($iso2),
            ])
            ->all();

        DB::table('sbos')
            ->select(['id', 'address', 'city', 'state', 'country', 'latitude', 'longitude'])
            ->orderBy('id')
            ->chunk(200, function ($sbos) use ($codes): void {
                $rows = [];

                foreach ($sbos as $sbo) {
                    // Nothing to carry over: a listing with no address at all
                    // starts with an empty list rather than a blank row.
                    if (blank($sbo->address) && blank($sbo->city) && blank($sbo->country)) {
                        continue;
                    }

                    $rows[] = [
                        'sbo_id' => $sbo->id,
                        'label' => 'Head Office',
                        'address' => $sbo->address,
                        'city' => $sbo->city,
                        'state' => $sbo->state,
                        'country' => $sbo->country,
                        'country_code' => $codes[mb_strtolower(mb_trim((string) $sbo->country))] ?? null,
                        'latitude' => $sbo->latitude,
                        'longitude' => $sbo->longitude,
                        'is_primary' => true,
                        'position' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ([] !== $rows) {
                    DB::table('sbo_addresses')->insert($rows);
                }
            });
    }
};
