<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a live, human-handled chatbot conversation.
 *
 * The bot's own questions and answers live in ChatbotConversation; this is only
 * what a visitor and an administrator say to each other once a conversation has
 * been taken over. Kept apart because they are read differently — one is a lead
 * log, the other is a chat transcript.
 */
final class ChatbotMessage extends Model
{
    public const SENDER_VISITOR = 'visitor';

    public const SENDER_ADMIN = 'admin';

    protected $fillable = ['session_id', 'sender', 'admin_id', 'body'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
