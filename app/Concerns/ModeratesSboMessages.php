<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Http\Controllers\SboReportController;
use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboMessageReaction;
use App\Models\SboMessageTranslation;
use App\Models\SboNetworkReport;
use App\Support\Locales;
use App\Support\RegistrySbo;
use App\Support\Translation\Translator;

/**
 * The message actions an administrator can take, acting as the registry.
 *
 * React, pin, translate, report and delete — the same things a member does to a
 * message, shared by the admin screens that show the registry's conversations
 * (the category groups and the private inbox) so both offer the identical menu
 * from one place rather than two copies drifting apart.
 *
 * Reply and reply-privately are NOT here: each screen composes differently (a
 * group has a group-or-private composer; the inbox has one per open thread), so
 * those stay with the screen while the per-message actions live here.
 */
trait ModeratesSboMessages
{
    /** messageId => translated text, shown inline while set. */
    public array $translations = [];

    /** The message the report modal is open for, and its fields. */
    public ?int $reportingId = null;

    public string $reportReason = '';

    public string $reportDetails = '';

    /**
     * A message this admin may act on, or null.
     *
     * The default is any message in a conversation the registry is part of —
     * which is every group and every thread it has been written to. A screen
     * that must be tighter (the groups page, which must not touch a private
     * thread) overrides this.
     */
    protected function messageInScope(int $id): ?SboMessage
    {
        $registry = RegistrySbo::current();

        if (null === $registry) {
            return null;
        }

        return SboMessage::query()
            ->whereKey($id)
            ->whereHas('conversation.participants', fn ($q) => $q->whereKey($registry->id))
            ->with(['sbo', 'conversation'])
            ->first();
    }

    public function react(int $messageId, string $emoji): void
    {
        if (! in_array($emoji, SboMessageReaction::ALLOWED, true)) {
            return;
        }

        $message = $this->messageInScope($messageId);

        if (null === $message) {
            return;
        }

        $existing = SboMessageReaction::query()
            ->where('message_id', $message->id)
            ->where('user_id', auth()->id())
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();

            return;
        }

        SboMessageReaction::create([
            'message_id' => $message->id,
            'user_id' => auth()->id(),
            'emoji' => $emoji,
        ]);
    }

    public function pinMessage(int $messageId): void
    {
        $message = $this->messageInScope($messageId);

        if (null === $message) {
            return;
        }

        if (null !== $message->pinned_at) {
            $message->forceFill(['pinned_at' => null, 'pinned_by_user_id' => null])->save();

            return;
        }

        // One pin per conversation: pinning something else moves the pin.
        SboMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_user_id' => null]);

        $message->forceFill(['pinned_at' => now(), 'pinned_by_user_id' => auth()->id()])->save();
    }

    public function translateMessage(int $messageId): void
    {
        // Toggle off if already showing.
        if (isset($this->translations[$messageId])) {
            unset($this->translations[$messageId]);

            return;
        }

        $message = $this->messageInScope($messageId);

        if (null === $message || '' === (string) $message->body) {
            return;
        }

        $translator = app(Translator::class);

        if (! $translator->isConfigured()) {
            session()->flash('error', 'Translation is not available.');

            return;
        }

        $target = Locales::resolve(request(), auth()->user());

        $cached = $message->translations()->where('locale', $target)->first();

        if (null !== $cached) {
            $this->translations[$messageId] = $cached->body;

            return;
        }

        try {
            $result = $translator->translate((string) $message->body, $target);
        } catch (\Throwable $exception) {
            report($exception);
            session()->flash('error', 'That message could not be translated right now.');

            return;
        }

        SboMessageTranslation::firstOrCreate(
            ['message_id' => $message->id, 'locale' => $target],
            [
                'body' => $result->text,
                'source_locale' => $result->detectedSource,
                'provider' => (string) config('translation.driver'),
            ],
        );

        $this->translations[$messageId] = $result->text;
    }

    /** Open the report modal for a message. */
    public function openReport(int $messageId): void
    {
        if (null === $this->messageInScope($messageId)) {
            return;
        }

        $this->reportingId = $messageId;
        $this->reportReason = '';
        $this->reportDetails = '';
        $this->resetErrorBag(['reportReason', 'reportDetails']);
    }

    public function cancelReport(): void
    {
        $this->reportingId = null;
    }

    /** Submit the modal — the same reason list a member reports a listing with. */
    public function submitReport(): void
    {
        if (null === $this->reportingId) {
            return;
        }

        $this->validate([
            'reportReason' => ['required', 'in:' . implode(',', array_keys(SboReportController::reasons()))],
            'reportDetails' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->reportMessage($this->reportingId, $this->reportReason, $this->reportDetails);

        $this->reportingId = null;
        $this->reportReason = '';
        $this->reportDetails = '';
    }

    public function reportMessage(int $messageId, string $reason, ?string $details = null): void
    {
        $message = $this->messageInScope($messageId);

        if (null === $message) {
            return;
        }

        $reasons = SboReportController::reasons();

        if (! array_key_exists($reason, $reasons)) {
            return;
        }

        $registry = RegistrySbo::ensure();

        $open = SboNetworkReport::query()
            ->where('message_id', $message->id)
            ->whereIn('status', [SboNetworkReport::STATUS_OPEN, SboNetworkReport::STATUS_REVIEWING])
            ->exists();

        if ($open) {
            session()->flash('success', 'A report is already open for this message.');

            return;
        }

        $note = null === $details || '' === trim($details)
            ? 'Flagged by an administrator from the admin network screens.'
            : trim($details);

        SboNetworkReport::create([
            'reporter_sbo_id' => $registry->id,
            'reported_sbo_id' => $message->sbo_id,
            'message_id' => $message->id,
            'reason' => $reasons[$reason],
            'details' => $note,
            'status' => SboNetworkReport::STATUS_OPEN,
        ]);

        session()->flash('success', 'Message flagged to the reports queue.');
    }

    /**
     * Delete a message, for everyone. Logged: removing something a member wrote
     * is an administrative act and should be attributable.
     */
    public function deleteMessage(int $messageId): void
    {
        $message = $this->messageInScope($messageId);

        if (null === $message) {
            return;
        }

        $room = $message->conversation;
        $isGroup = $room && SboConversation::TYPE_INDUSTRY === $room->type;
        $message->delete();

        ActivityLog::create([
            'type' => $isGroup ? 'group_message_deleted' : 'network_message_deleted',
            'title' => $isGroup ? (($room->industry ?? 'Category') . ' Group') : 'Direct thread',
            'description' => 'Message removed from '
                . ($isGroup ? 'the ' . e($room->industry ?? 'category') . ' group chat' : 'a private thread')
                . ' by <strong>' . e(auth()->user()->name ?? 'admin') . '</strong>.',
            'status' => 'completed',
            'user_id' => auth()->id(),
        ]);

        session()->flash('success', 'Message deleted.');
    }

    /** The registry's one-to-one thread with a business, created on first need. */
    protected function registryDirectWith(int $sboId, Sbo $registry): SboConversation
    {
        $existing = SboConversation::query()
            ->where('type', SboConversation::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->whereKey($registry->id))
            ->whereHas('participants', fn ($q) => $q->whereKey($sboId))
            ->has('participants', '=', 2)
            ->first();

        if ($existing instanceof SboConversation) {
            return $existing;
        }

        $conversation = SboConversation::create([
            'created_by_sbo_id' => $registry->id,
            'type' => SboConversation::TYPE_DIRECT,
            'last_message_at' => now(),
        ]);

        $conversation->participants()->syncWithoutDetaching([$registry->id, $sboId]);

        return $conversation;
    }
}
