<?php

declare(strict_types=1);

use App\Support\IndustryGroupChat;
use Illuminate\Database\Migrations\Migration;

/**
 * Hand every existing industry room to the registry.
 *
 * New rooms get it from IndustryGroupChat::for(). The ones already in the
 * database were created with `created_by_sbo_id` pointing at whichever
 * business happened to be verified first, and with the registry not in them at
 * all — so on an existing installation "Message admin" and the Admin > Member
 * Messages screen would both be right about new rooms and wrong about every
 * old one.
 *
 * Idempotent: adopt() only writes when the owner differs, and membership is
 * synced without detaching, so nobody's last_read_at is disturbed.
 */
return new class() extends Migration
{
    public function up(): void
    {
        IndustryGroupChat::backfillAll();
    }

    public function down(): void
    {
        // Nothing to undo. Removing the registry from rooms it now owns would
        // leave them ownerless again, which is the state this repaired.
    }
};
