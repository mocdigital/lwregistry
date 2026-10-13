<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class SboConnection extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Disconnected by either side after being accepted.
     *
     * Distinct from `declined`, which is a request refused before it was ever
     * a connection. The row survives so the conversation it opened keeps its
     * participants — a deleted row nulls `sbo_conversations.connection_id` and
     * the thread loses the name of whoever it is with.
     */
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'requester_sbo_id',
        'recipient_sbo_id',
        'status',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    /** Whether a fresh request may be raised over this row. */
    public function isReconnectable(): bool
    {
        return in_array($this->status, [self::STATUS_DECLINED, self::STATUS_REMOVED], true);
    }

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Sbo::class, 'requester_sbo_id');
    }

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Sbo::class, 'recipient_sbo_id');
    }

    /**
     * @return HasOne<SboConversation, $this>
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(SboConversation::class, 'connection_id');
    }

    public function isAccepted(): bool
    {
        return self::STATUS_ACCEPTED === $this->status;
    }

    public function involves(Sbo $sbo): bool
    {
        return (int) $this->requester_sbo_id === (int) $sbo->id
            || (int) $this->recipient_sbo_id === (int) $sbo->id;
    }

    public function otherSbo(Sbo $sbo): ?Sbo
    {
        if ((int) $this->requester_sbo_id === (int) $sbo->id) {
            return $this->recipient;
        }

        if ((int) $this->recipient_sbo_id === (int) $sbo->id) {
            return $this->requester;
        }

        return null;
    }
}
