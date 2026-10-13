<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's emoji on one message.
 *
 * Keyed by user rather than by SBO: a reaction is a person's, and two people
 * from the same business reacting are two reactions, not one overwritten.
 */
final class SboMessageReaction extends Model
{
    /**
     * What may be sent.
     *
     * A closed set rather than free text. Anything goes through the same
     * column either way, but an open field is a place to paste a paragraph, and
     * a picker with six choices is faster to use than one with two thousand.
     *
     * @var array<int, string>
     */
    public const ALLOWED = ['👍', '❤️', '😂', '😮', '🙏', '✅'];

    protected $fillable = ['message_id', 'user_id', 'emoji'];

    /**
     * @return BelongsTo<SboMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SboMessage::class, 'message_id');
    }
}
