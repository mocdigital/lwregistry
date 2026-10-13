<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The opportunity-alerts list.
 *
 * "Subscribe Now" on the home rail has been a button with no handler since the
 * screen was built — it said "Stay ahead. Subscribe for alerts." and did
 * nothing at all. This is the list it writes to.
 *
 * Deliberately its own table rather than a flag on `users`: most people who
 * want the alerts are not members yet, which is the point of putting the card
 * on the public home page. A subscriber who later registers is matched by
 * email, not merged away.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            /*
             * Unique: subscribing twice is a person checking it worked, not a
             * request for two copies of everything. The endpoint updates the
             * existing row instead of failing at them.
             */
            $table->string('email')->unique();

            /*
             * Which card they used. There is one today; the footer and the
             * procurement rail are obvious next ones, and a list you cannot
             * segment by origin is a list you cannot report on.
             */
            $table->string('source')->default('home_rail');

            /*
             * `unsubscribed_at`, not a delete. A deleted row cannot stop the
             * same address being re-added by an import, and it destroys the
             * evidence that consent was given and withdrawn.
             */
            $table->timestamp('unsubscribed_at')->nullable();

            // Null for a guest, set when a signed-in member subscribes.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->ipAddress('ip_address')->nullable();
            $table->timestamps();

            $table->index('unsubscribed_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
