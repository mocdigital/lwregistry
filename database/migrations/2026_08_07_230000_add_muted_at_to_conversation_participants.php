<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a member turn off notifications for one conversation.
 *
 * An industry room can have hundreds of businesses in it, and every message
 * notifies everyone. Without a way to mute, a busy room is a reason to stop
 * reading the whole inbox.
 *
 * On the participants pivot rather than a settings table, because muting is a
 * property of one member's relationship to one conversation — the same place
 * `last_read_at` already lives, and the row is already loaded wherever the
 * question gets asked.
 *
 * A timestamp rather than a boolean: knowing when someone muted a room is the
 * difference between "never wanted this" and "gave up on it in March".
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbo_conversation_participants', function (Blueprint $table): void {
            $table->timestamp('muted_at')->nullable()->after('last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('sbo_conversation_participants', function (Blueprint $table): void {
            $table->dropColumn('muted_at');
        });
    }
};
