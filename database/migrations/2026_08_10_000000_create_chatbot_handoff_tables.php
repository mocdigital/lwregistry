<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handing a conversation from the bot to a person.
 *
 * The assistant answers most things itself, but "Talk to a person" has to mean
 * a person — so a session can be handed off, an administrator can take it over,
 * and the two can talk in a live thread the bot steps out of. Three pieces:
 * where a session is in that handoff, the live messages once it is human, and
 * which administrators are online to take one.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_sessions', function (Blueprint $table): void {
            /*
             * null while the bot is handling it, then:
             *   requested — the visitor asked for a person and is waiting
             *   live      — an administrator has taken over
             *   missed    — nobody was online; support will follow up
             *   ended     — the human conversation is over
             */
            $table->string('handoff_state', 16)->nullable()->index();
            $table->timestamp('handoff_requested_at')->nullable();

            // Whose it is once taken over. Kept after it ends, so the log says
            // who handled it.
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();

            // Cleared when an administrator has seen the request, so the ring
            // sounds once per request rather than on every poll.
            $table->boolean('handoff_acknowledged')->default(false);
        });

        Schema::create('chatbot_messages', function (Blueprint $table): void {
            $table->id();

            // The session, not a user: a visitor is usually a guest, and the
            // session is what ties their thread together — the same key the bot
            // conversation uses.
            $table->string('session_id', 64)->index();

            // 'visitor' or 'admin'. The bot's own replies stay in
            // chatbot_conversations; this table is the human thread only.
            $table->string('sender', 16);
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');

            // So the widget can poll for "anything after id N" cheaply.
            $table->timestamps();
            $table->index(['session_id', 'id']);
        });

        Schema::create('chatbot_admin_presence', function (Blueprint $table): void {
            // One row per administrator, upserted on a heartbeat. Online is
            // decided by how recent last_seen_at is, so nothing has to be
            // cleaned up when a tab simply closes.
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_admin_presence');
        Schema::dropIfExists('chatbot_messages');

        Schema::table('chatbot_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('admin_id');
            $table->dropColumn(['handoff_state', 'handoff_requested_at', 'handoff_acknowledged']);
        });
    }
};
