<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark one listing as the registry's own.
 *
 * Members need a way to write to the registry privately, and administrators a
 * way to write back. The chat is entirely SBO-to-SBO — conversations,
 * participants, attachments, notifications and the broadcast channel all key
 * on an SBO — so the cheapest correct answer is to give the registry an SBO to
 * be, rather than to teach every one of those about a second kind of party.
 *
 * A flag rather than a slug in config: the row is what everything else already
 * points at, and a config value naming a slug is one rename away from silently
 * addressing nothing.
 *
 * It is deliberately not `approved`, which is what keeps it out of the
 * directory, the categories page and the home page's featured row — all three
 * filter on that, so no query has to learn about this flag to exclude it.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->boolean('is_registry')->default(false)->after('is_active');
            $table->index('is_registry');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropIndex(['is_registry']);
            $table->dropColumn('is_registry');
        });
    }
};
