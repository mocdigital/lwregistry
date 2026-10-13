<?php

declare(strict_types=1);

use App\Models\SboConversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// User keys are UUIDs, so compare as strings (not ints).
Broadcast::channel('App.Models.User.{id}', fn ($user, $id) => (string) $user->id === (string) $id);

/*
 * An SBO conversation thread. Membership is exactly the rule the write
 * endpoints enforce — one of your SBOs is a party to the conversation, either
 * through its connection or through the participants pivot — so subscribing
 * cannot reveal a thread you could not already load over HTTP.
 */
Broadcast::channel('sbo.conversation.{conversationId}', fn (User $user, string $conversationId): bool => SboConversation::query()
    ->visibleTo($user->sbos()->pluck('id'))
    ->whereKey($conversationId)
    ->exists());
