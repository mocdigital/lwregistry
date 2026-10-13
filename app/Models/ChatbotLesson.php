<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question a visitor asked and the answer an administrator gave it.
 *
 * The raw material the assistant learns from: once approved, the pair is
 * written into the knowledge bank as a passage, so the next visitor asking the
 * same thing is answered from it rather than handed to a person again.
 */
final class ChatbotLesson extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = ['session_id', 'question', 'answer', 'status', 'admin_id', 'passage_id'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
