<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replies, reactions, and the marks a message can carry.
 *
 * A chat where every message is a flat statement cannot hold a conversation
 * with more than two people in it: by the time you answer, the thing you are
 * answering has scrolled away.
 *
 * REPLIES are a quote, not a tree. Every reply points at exactly one message
 * and the thread shows that one message above it — reply to a reply and you
 * quote the reply, which is what the chains people actually write need. A
 * nested tree would mean indenting a column that is already narrow, and
 * deciding what to do at depth twelve.
 *
 * `nullOnDelete`: deleting a quoted message must not delete the answer to it.
 *
 * DELETION is a tombstone rather than a removed row. The reply that quotes a
 * message has to keep something to quote, and a thread that silently loses a
 * line reads as though it was never sent.
 *
 * PIN is per conversation and STAR is per person, which is why one is a column
 * here and the other is a table.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbo_messages', function (Blueprint $table): void {
            $table->foreignId('reply_to_message_id')
                ->nullable()
                ->after('conversation_id')
                ->constrained('sbo_messages')
                ->nullOnDelete();

            // Deleted for everyone. The row stays so replies keep their quote
            // and the thread keeps its shape; the body is emptied on delete.
            $table->timestamp('deleted_for_all_at')->nullable();

            // Pinned in the conversation, for everyone in it.
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('sbo_message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('sbo_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The emoji itself, not an id into a table of them. It is the
             * value, it is stable, and a lookup table would only be a second
             * place for the same character to live.
             */
            $table->string('emoji', 16);

            $table->timestamps();

            // One of each emoji per person per message. Reacting again removes
            // it, and this is what stops a double submit writing two.
            $table->unique(['message_id', 'user_id', 'emoji']);
        });

        Schema::create('sbo_message_stars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('sbo_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // A star is a private bookmark, so it is per person and there is
            // only ever one.
            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_message_stars');
        Schema::dropIfExists('sbo_message_reactions');

        Schema::table('sbo_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pinned_by_user_id');
            $table->dropConstrainedForeignId('reply_to_message_id');
            $table->dropColumn(['deleted_for_all_at', 'pinned_at']);
        });
    }
};
