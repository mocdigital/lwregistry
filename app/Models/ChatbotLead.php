<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor the assistant has met.
 *
 * One row per session, so a conversation of ten questions is one lead rather
 * than ten.
 */
final class ChatbotLead extends Model
{
    protected $fillable = ['session_id', 'user_id', 'name', 'email', 'first_question'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
