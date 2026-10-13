<?php

declare(strict_types=1);

use App\Support\IndustryGroupChat;
use Illuminate\Database\Migrations\Migration;

/**
 * Put every already-verified business into its industry's group chat.
 *
 * Membership is kept in step by SboObserver from here on, but businesses
 * verified before these rooms existed would otherwise never be added — the
 * observer only fires on a write, and an SBO approved last month is not going
 * to be written again just because the feature shipped.
 */
return new class() extends Migration
{
    public function up(): void
    {
        IndustryGroupChat::backfillAll();
    }

    public function down(): void
    {
        // The rooms and their membership go with the `industry` column, which
        // the migration before this one drops.
    }
};
