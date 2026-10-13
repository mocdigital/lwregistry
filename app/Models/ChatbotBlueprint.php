<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One conversation, drawn as a graph.
 */
final class ChatbotBlueprint extends Model
{
    protected $fillable = ['name', 'description', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    /** The live blueprint, or null when nothing has been published. */
    public static function live(): ?self
    {
        return self::query()->where('is_active', true)->with(['nodes', 'wires'])->first();
    }

    /**
     * @return HasMany<ChatbotNode, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(ChatbotNode::class, 'blueprint_id');
    }

    /**
     * @return HasMany<ChatbotWire, $this>
     */
    public function wires(): HasMany
    {
        return $this->hasMany(ChatbotWire::class, 'blueprint_id');
    }

    /**
     * Make this the one visitors talk to.
     *
     * Exactly one is live, so publishing another retires the current one in
     * the same transaction — two live blueprints would mean the assistant
     * behaved differently depending on which row a query happened to return
     * first.
     */
    public function publish(): void
    {
        DB::transaction(function (): void {
            self::query()->where('id', '!=', $this->id)->update(['is_active' => false]);
            $this->forceFill(['is_active' => true])->save();
        });
    }

    /** Where a conversation starts: the node nothing wires into. */
    public function entryNode(): ?ChatbotNode
    {
        $wiredInto = $this->wires->pluck('to_node')->all();

        return $this->nodes->firstWhere(
            fn (ChatbotNode $node): bool => ! in_array($node->key, $wiredInto, true),
        ) ?? $this->nodes->first();
    }
}
