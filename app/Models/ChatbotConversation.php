<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One question a visitor asked, and what came back.
 *
 * The unanswered ones are the valuable half: a list of what customers want to
 * know and the sales material that does not yet exist.
 */
final class ChatbotConversation extends Model
{
    protected $fillable = ['session_id', 'user_id', 'question', 'answer', 'answered', 'passage_ids'];

    protected $casts = [
        'answered' => 'boolean',
        'passage_ids' => 'array',
    ];
}
