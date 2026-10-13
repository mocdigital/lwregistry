<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one visitor has got to, and what the flow has collected from them.
 */
final class ChatbotSession extends Model
{
    public const HANDOFF_REQUESTED = 'requested';

    public const HANDOFF_LIVE = 'live';

    public const HANDOFF_MISSED = 'missed';

    public const HANDOFF_ENDED = 'ended';

    protected $fillable = [
        'session_id', 'blueprint_id', 'current_node', 'data',
        'handoff_state', 'handoff_requested_at', 'admin_id', 'handoff_acknowledged',
    ];

    protected $casts = [
        'data' => 'array',
        'handoff_requested_at' => 'datetime',
        'handoff_acknowledged' => 'boolean',
    ];

    public function value(string $key, mixed $default = null): mixed
    {
        return ($this->data ?? [])[$key] ?? $default;
    }

    public function remember(string $key, mixed $value): void
    {
        $this->forceFill(['data' => array_merge($this->data ?? [], [$key => $value])])->save();
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** A person is handling, or being fetched to handle, this conversation. */
    public function isWithHuman(): bool
    {
        return in_array($this->handoff_state, [self::HANDOFF_REQUESTED, self::HANDOFF_LIVE], true);
    }

    /** The name the flow collected, for the admin list to label the thread. */
    public function visitorName(): string
    {
        return (string) $this->value('name', 'Visitor');
    }

    public function visitorEmail(): ?string
    {
        $email = $this->value('email');

        return is_string($email) && '' !== $email ? $email : null;
    }
}
