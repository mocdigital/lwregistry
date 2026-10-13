<?php

declare(strict_types=1);

use App\Models\SboConversation;
use App\Support\IndustryGroupChat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the network group chats from one-per-industry to one-per-category.
 *
 * The old rooms were keyed by the broad industry sector, which collapsed the
 * ~39 categories into ~18 rooms. They are retired here and rebuilt per
 * category, so every category has its own group (and the registry sits in each).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Retire the old industry-keyed rooms and everything hanging off them.
        // Message attachments and translations fall away with the messages by
        // FK cascade; participants and stars are cleared here.
        $ids = DB::table('sbo_conversations')
            ->where('type', SboConversation::TYPE_INDUSTRY)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('sbo_messages')->whereIn('conversation_id', $ids)->delete();
            DB::table('sbo_conversation_participants')->whereIn('conversation_id', $ids)->delete();
            DB::table('sbo_conversation_stars')->whereIn('conversation_id', $ids)->delete();
            DB::table('sbo_conversations')->whereIn('id', $ids)->delete();
        }

        // Build a room for every category and put every verified business into
        // the one for its category.
        IndustryGroupChat::backfillAll();
    }

    public function down(): void
    {
        // One-way: the industry rooms and their messages are gone. The category
        // rooms rebuild themselves from membership on the next sync, so there is
        // nothing safe or useful to reverse to.
    }
};
