<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

/**
 * What a node in a conversation blueprint does.
 *
 * Six, deliberately. Every extra type is another shape on the canvas somebody
 * has to understand, and these six compose into the flows an assistant of this
 * kind actually needs — greet, ask who you are, offer some things to ask
 * about, answer from what has been uploaded, hand over.
 */
final class NodeType
{
    /** Says something and moves straight on. Greetings and confirmations. */
    public const SAY = 'say';

    /**
     * Says something, waits for a reply, and keeps it under `field`.
     *
     * The name and the email are both this, differing only in what they store
     * and whether the answer is checked as an address.
     */
    public const ASK = 'ask';

    /**
     * Offers a set of options as buttons, one output pin each.
     *
     * This is the branch. There is no separate condition node because the
     * branch a visitor takes is the button they pressed, and a flow that reads
     * as "they chose this, so go here" is one an administrator can follow.
     */
    public const CHOICE = 'choice';

    /** Answers from the uploaded documents. The knowledge bank, as one step. */
    public const KNOWLEDGE = 'knowledge';

    /** Records the visitor as a lead, with whatever has been collected. */
    public const CAPTURE = 'capture';

    /** Says a last thing and stops. */
    public const END = 'end';

    /**
     * Every type, with what the editor should call it and which pins it has.
     *
     * The pins live here rather than in the editor's JavaScript so that the
     * canvas and the runtime cannot disagree about what a node can connect to.
     *
     * @return array<string, array{label: string, pins: array<int, string>|null, hint: string}>
     */
    public static function all(): array
    {
        return [
            self::SAY => [
                'label' => 'Say',
                'pins' => ['out'],
                'hint' => 'Sends a message and continues.',
            ],
            self::ASK => [
                'label' => 'Ask',
                'pins' => ['out'],
                'hint' => 'Asks a question and stores the reply.',
            ],
            self::CHOICE => [
                'label' => 'Choice',
                // Null: a choice node's pins are its options, so they are read
                // from its config rather than being fixed.
                'pins' => null,
                'hint' => 'Offers buttons. One output per option.',
            ],
            self::KNOWLEDGE => [
                'label' => 'Knowledge bank',
                'pins' => ['answered', 'unanswered'],
                'hint' => 'Answers from the uploaded documents.',
            ],
            self::CAPTURE => [
                'label' => 'Capture lead',
                'pins' => ['out'],
                'hint' => 'Saves the visitor as a lead.',
            ],
            self::END => [
                'label' => 'End',
                'pins' => [],
                'hint' => 'Says a last thing and stops.',
            ],
        ];
    }

    /**
     * The output pins a node offers.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    public static function pinsFor(string $type, array $config = []): array
    {
        if (self::CHOICE === $type) {
            $pins = array_values(array_map(
                static fn (array $option): string => (string) ($option['pin'] ?? ''),
                (array) ($config['options'] ?? []),
            ));

            /*
             * A choice can offer what the knowledge bank actually holds rather
             * than a list somebody typed. Those options all leave by ONE pin —
             * the topic they name becomes the question, so a single wire into
             * a knowledge node serves however many documents exist.
             *
             * Without that they would need a wire each, and adding a document
             * would silently leave a button connected to nothing.
             */
            if ('knowledge' === ($config['source'] ?? null)) {
                $pins[] = (string) ($config['topic_pin'] ?? 'topic');
            }

            return array_values(array_unique($pins));
        }

        return self::all()[$type]['pins'] ?? [];
    }
}
