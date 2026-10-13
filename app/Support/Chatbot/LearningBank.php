<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use App\Models\ChatbotDocument;
use App\Models\ChatbotLesson;
use App\Models\ChatbotMessage;
use App\Models\ChatbotPassage;
use App\Models\ChatbotSession;
use App\Models\ChatbotSetting;
use Illuminate\Support\Facades\DB;

/**
 * The assistant learning from the people who answer for it.
 *
 * When an administrator answers a question the bank could not, that answer is
 * captured as a lesson. Approved — by hand, or automatically where that is
 * switched on — it is written into the bank as a passage: the question in the
 * searchable text so it is found by what a visitor types, the answer as the
 * content that is shown. The next person to ask the same thing is then answered
 * by the assistant rather than waiting for a person again.
 *
 * The learned passages live in one document, so an administrator can see
 * everything the assistant has picked up in Admin > Sales Assistant, and edit
 * or remove any of it — learning that cannot be corrected is not learning worth
 * having.
 */
final class LearningBank
{
    private const DOCUMENT_TITLE = 'Learned from support';

    /** Too short to be a real answer — "ok", "one sec", "sure". */
    private const MIN_ANSWER_LENGTH = 20;

    /**
     * Record an administrator's reply as something to learn, pairing it with
     * the question it answered.
     *
     * The question is whatever the visitor last asked before this reply — the
     * message in the live thread if there is one, otherwise the question that
     * sent the conversation to a person in the first place. Nothing is captured
     * for a throwaway line, or when there is no question to attach it to.
     */
    public static function capture(ChatbotSession $session, string $answer): void
    {
        $answer = mb_trim($answer);

        if (mb_strlen($answer) < self::MIN_ANSWER_LENGTH) {
            return;
        }

        $question = self::questionFor($session);

        if (null === $question) {
            return;
        }

        // Do not capture the same pair twice while it is still pending.
        $existing = ChatbotLesson::query()
            ->where('session_id', $session->session_id)
            ->where('answer', $answer)
            ->where('status', ChatbotLesson::STATUS_PENDING)
            ->exists();

        if ($existing) {
            return;
        }

        $lesson = ChatbotLesson::create([
            'session_id' => $session->session_id,
            'question' => $question,
            'answer' => $answer,
            'status' => ChatbotLesson::STATUS_PENDING,
            'admin_id' => $session->admin_id,
        ]);

        // Straight into the bank when an administrator has said to trust the
        // replies; otherwise it waits on the review list.
        if (ChatbotSetting::bool(ChatbotSetting::AUTO_LEARN)) {
            self::approve($lesson);
        }
    }

    /** Write an approved lesson into the bank as a passage. */
    public static function approve(ChatbotLesson $lesson): void
    {
        DB::transaction(function () use ($lesson): void {
            $document = ChatbotDocument::updateOrCreate(
                ['title' => self::DOCUMENT_TITLE],
                [
                    'disk' => 'chat',
                    'path' => '',
                    'original_name' => 'generated',
                    'mime_type' => 'text/plain',
                    'size' => 0,
                    'status' => ChatbotDocument::STATUS_READY,
                    'error' => null,
                ],
            );

            $passage = ChatbotPassage::create([
                'document_id' => $document->id,
                'ordinal' => (int) $document->passages()->max('ordinal') + 1,
                // Answer shown, question searchable — the same shape the curated
                // library uses, so a learned answer reads as an answer, not a
                // FAQ entry quoting the question back.
                'content' => $lesson->answer,
                'searchable' => ChatbotPassage::normalise($lesson->question . ' ' . $lesson->answer),
            ]);

            $document->forceFill(['passage_count' => $document->passages()->count()])->save();

            $lesson->forceFill([
                'status' => ChatbotLesson::STATUS_APPROVED,
                'passage_id' => $passage->id,
            ])->save();
        });
    }

    public static function dismiss(ChatbotLesson $lesson): void
    {
        $lesson->forceFill(['status' => ChatbotLesson::STATUS_DISMISSED])->save();
    }

    /**
     * The question an admin reply is answering.
     *
     * The last thing the visitor said in the live thread, or — before they have
     * said anything there — the question that escalated the conversation.
     */
    private static function questionFor(ChatbotSession $session): ?string
    {
        $lastVisitor = ChatbotMessage::query()
            ->where('session_id', $session->session_id)
            ->where('sender', ChatbotMessage::SENDER_VISITOR)
            ->orderByDesc('id')
            ->value('body');

        $question = mb_trim((string) ($lastVisitor ?? $session->value('last_question', '')));

        return '' === $question ? null : $question;
    }
}
