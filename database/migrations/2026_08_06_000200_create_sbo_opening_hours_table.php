<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Opening hours, per listing.
 *
 * A `business_hours` table already exists with almost this shape, but its
 * foreign key points at `businesses` — the legacy model the public profile no
 * longer uses. Rather than repoint a live table or add a nullable second key
 * to it, SBOs get their own; `businesses` keeps its own hours until that model
 * is retired.
 *
 * One row per day the owner has actually set. A day with no row is "not
 * specified" and is skipped on the profile, which is different from a day with
 * is_closed = true, which reads "Closed".
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_opening_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sbo_id')->constrained()->cascadeOnDelete();
            // 0 = Sunday .. 6 = Saturday, matching Carbon's dayOfWeek.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            // A day cannot have two conflicting entries.
            $table->unique(['sbo_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_opening_hours');
    }
};
