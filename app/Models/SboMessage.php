<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SboMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        // The message this one answers. A quote, not a tree — see the
        // migration for why.
        'reply_to_message_id',
        'sbo_id',
        'user_id',
        'body',
        // The language it was written in; see the migration for why it is
        // stored rather than detected per render.
        'detected_locale',
        'detected_at',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'deleted_for_all_at' => 'datetime',
        'pinned_at' => 'datetime',
    ];

    /** Deleted for everyone; the row stays so replies keep their quote. */
    public function isDeleted(): bool
    {
        return null !== $this->deleted_for_all_at;
    }

    /**
     * The message this one answers, if any.
     *
     * @return BelongsTo<SboMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    /**
     * @return HasMany<SboMessageReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(SboMessageReaction::class, 'message_id');
    }

    /**
     * Files sent with this message. Several are allowed on one message, so a
     * quotation and the drawing it refers to arrive together rather than as
     * two entries in the thread.
     *
     * @return HasMany<SboMessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SboMessageAttachment::class, 'message_id');
    }

    /**
     * @return BelongsTo<SboConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SboConversation::class, 'conversation_id');
    }

    /**
     * Cached machine translations of this message, one per language.
     *
     * @return HasMany<SboMessageTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(SboMessageTranslation::class, 'message_id');
    }

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
