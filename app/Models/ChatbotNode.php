<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Chatbot\NodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a conversation.
 */
final class ChatbotNode extends Model
{
    protected $fillable = ['blueprint_id', 'key', 'type', 'config', 'x', 'y'];

    protected $casts = [
        'config' => 'array',
        'x' => 'integer',
        'y' => 'integer',
    ];

    /**
     * @return BelongsTo<ChatbotBlueprint, $this>
     */
    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(ChatbotBlueprint::class, 'blueprint_id');
    }

    /** @return array<int, string> */
    public function pins(): array
    {
        return NodeType::pinsFor($this->type, $this->config ?? []);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }
}
