<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use App\Models\ChatbotBlueprint;
use App\Models\ChatbotNode;
use App\Models\ChatbotWire;
use Illuminate\Support\Facades\DB;

/**
 * The conversation the assistant starts with.
 *
 * Greet, ask a name, welcome them by it, ask for an address they may skip,
 * offer four things to ask about, and answer everything else from the
 * knowledge bank. That is the flow that was described; the point of building
 * it as a blueprint rather than as code is that the next person to change it
 * does not have to be a developer.
 *
 * Seeded rather than hardcoded, and seeded ONCE — it is a starting point an
 * administrator is expected to rearrange, so re-running this must not undo
 * their work.
 */
final class DefaultBlueprint
{
    public static function install(?int $createdBy = null): ChatbotBlueprint
    {
        $existing = ChatbotBlueprint::query()->where('name', 'Welcome')->first();

        if ($existing instanceof ChatbotBlueprint) {
            return $existing;
        }

        return DB::transaction(function () use ($createdBy): ChatbotBlueprint {
            $blueprint = ChatbotBlueprint::create([
                'name' => 'Welcome',
                'description' => 'Greets a visitor, takes their name and email, then answers from the knowledge bank.',
                'created_by' => $createdBy,
            ]);

            foreach ([...self::nodes(), ...self::topicNodes()] as $node) {
                ChatbotNode::create(['blueprint_id' => $blueprint->id] + $node);
            }

            foreach ([...self::wires(), ...self::topicWires()] as $wire) {
                ChatbotWire::create(['blueprint_id' => $blueprint->id] + $wire);
            }

            $blueprint->publish();

            return $blueprint->load(['nodes', 'wires']);
        });
    }

    /**
     * The nodes, with where they sit on the canvas.
     *
     * Laid out left to right in the order they run, so the graph reads the way
     * the conversation does before anybody has moved anything.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function nodes(): array
    {
        return [
            [
                'key' => 'greet',
                'type' => NodeType::SAY,
                'x' => 40,
                'y' => 40,
                'config' => ['text' => "Hi 👋 I'm the registry assistant. I can help with registration, fees, verification and what membership includes."],
            ],
            [
                'key' => 'ask_name',
                'type' => NodeType::ASK,
                'x' => 340,
                'y' => 40,
                'config' => [
                    'text' => 'Before we start — what should I call you?',
                    'field' => 'name',
                    'required_message' => 'Sorry, I missed that. What should I call you?',
                ],
            ],
            [
                'key' => 'welcome',
                'type' => NodeType::SAY,
                'x' => 640,
                'y' => 40,
                // :name is filled from what ask_name stored.
                'config' => ['text' => 'Welcome, :name.'],
            ],
            [
                'key' => 'ask_email',
                'type' => NodeType::ASK,
                'x' => 940,
                'y' => 40,
                'config' => [
                    'text' => "What's the best email to reach you on? You can skip this if you would rather not.",
                    'field' => 'email',
                    'validate' => 'email',
                    // Skippable on purpose: refusing to help until somebody
                    // hands over an address turns an assistant into a form.
                    'optional' => true,
                    'invalid_message' => 'That does not look like an email address — try again, or leave it blank to skip.',
                ],
            ],
            [
                'key' => 'capture',
                'type' => NodeType::CAPTURE,
                'x' => 1240,
                'y' => 40,
                'config' => [],
            ],
            [
                'key' => 'menu',
                'type' => NodeType::CHOICE,
                'x' => 40,
                'y' => 260,
                'config' => [
                    'text' => 'What can I help you with?',
                    // One option per subject in ConversationTopics, plus the
                    // two that are not subjects.
                    'options' => self::menuOptions(),
                    // A typed answer that matches no button is a question.
                    'fallback_pin' => 'other',
                ],
            ],
            [
                'key' => 'topics',
                'type' => NodeType::CHOICE,
                'x' => 40,
                'y' => 700,
                /*
                 * Offers what the bank actually holds, so uploading a document
                 * adds a conversation point without anybody touching the
                 * canvas. Every option leaves by ONE pin, because the topic
                 * named IS the question — otherwise each new document would
                 * need a wire, and a button connected to nothing.
                 */
                'config' => [
                    'text' => 'These are the things I know most about — or just ask me anything.',
                    'source' => 'knowledge',
                    'topic_pin' => 'topic',
                    'topic_limit' => 5,
                    'fallback_pin' => 'topic',
                ],
            ],
            [
                'key' => 'human',
                'type' => NodeType::SAY,
                'x' => 640,
                'y' => 700,
                // `handoff` turns this from a line of copy into a real request
                // for a person: the runner checks who is online and either
                // connects them or promises a follow-up. The text is supplied
                // by the handoff, not from here.
                'config' => [
                    'handoff' => true,
                    'text' => 'Let me get a person for you.',
                ],
            ],
            [
                'key' => 'ask_question',
                'type' => NodeType::ASK,
                'x' => 640,
                'y' => 260,
                'config' => [
                    'text' => 'Go ahead — what would you like to know?',
                    'field' => 'question',
                    'required_message' => 'Ask me anything about the registry.',
                ],
            ],
            [
                'key' => 'answer',
                'type' => NodeType::KNOWLEDGE,
                'x' => 940,
                'y' => 260,
                'config' => [],
            ],
            [
                'key' => 'more',
                'type' => NodeType::CHOICE,
                'x' => 1240,
                'y' => 260,
                'config' => [
                    'text' => 'Anything else I can help with?',
                    'options' => [
                        ['label' => 'Yes, another question', 'pin' => 'again'],
                        ['label' => 'Talk to a person', 'pin' => 'human'],
                        ['label' => 'No, thanks', 'pin' => 'done'],
                    ],
                    // Typing rather than pressing is another question.
                    'fallback_pin' => 'again',
                    // Offer related things to ask, drawn from what they last
                    // asked — so "anything else?" is a doorway, not a dead end.
                    'suggest_related' => true,
                ],
            ],
            [
                'key' => 'goodbye',
                'type' => NodeType::END,
                'x' => 1540,
                'y' => 260,
                'config' => ['text' => 'Thanks for stopping by. Somebody will follow up if you left an address.'],
            ],
        ];
    }

    /**
     * The menu, one option per subject.
     *
     * @return array<int, array<string, string>>
     */
    private static function menuOptions(): array
    {
        $options = [];

        foreach (ConversationTopics::all() as $key => $topic) {
            $options[] = ['label' => $topic['label'], 'pin' => $key];
        }

        $options[] = ['label' => 'What do you know about?', 'pin' => 'topics'];
        $options[] = ['label' => 'Talk to a person', 'pin' => 'human'];
        $options[] = ['label' => 'Ask me anything else', 'pin' => 'other'];

        return $options;
    }

    /**
     * A submenu per subject, and an answer node per question.
     *
     * Generated from ConversationTopics rather than written out: a hundred
     * nodes hand-written is a hundred places for a coordinate or a wire to be
     * wrong, and adding a question would mean editing three of them.
     *
     * Laid out in a column per subject so the canvas reads as one column of
     * questions under each subject rather than as a heap.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function topicNodes(): array
    {
        $nodes = [];
        $column = 0;

        foreach (ConversationTopics::all() as $key => $topic) {
            $x = 340 + ($column * 340);

            $nodes[] = [
                'key' => 'topic_' . $key,
                'type' => NodeType::CHOICE,
                'x' => $x,
                'y' => 460,
                'config' => [
                    'text' => $topic['label'] . ' — what would you like to know?',
                    'options' => array_values(array_map(
                        static fn (array $item, int $i): array => [
                            'label' => $item['q'],
                            'pin' => 'q' . $i,
                        ],
                        $topic['questions'],
                        array_keys($topic['questions']),
                    )),
                    // Typing at a submenu is a question, not a wrong button.
                    'fallback_pin' => 'ask',
                ],
            ];

            foreach ($topic['questions'] as $i => $item) {
                $nodes[] = [
                    'key' => 'ans_' . $key . '_' . $i,
                    'type' => NodeType::SAY,
                    'x' => $x,
                    'y' => 620 + ($i * 120),
                    'config' => ['text' => $item['a']],
                ];
            }

            $column++;
        }

        return $nodes;
    }

    /**
     * The wires for those: menu into each submenu, each question to its
     * answer, and every answer back to "anything else".
     *
     * @return array<int, array<string, string>>
     */
    private static function topicWires(): array
    {
        $wires = [];

        foreach (ConversationTopics::all() as $key => $topic) {
            $wires[] = ['from_node' => 'menu', 'from_pin' => $key, 'to_node' => 'topic_' . $key];

            // Typing at a submenu drops through to the open question, which
            // the knowledge bank answers.
            $wires[] = ['from_node' => 'topic_' . $key, 'from_pin' => 'ask', 'to_node' => 'ask_question'];

            foreach (array_keys($topic['questions']) as $i) {
                $wires[] = [
                    'from_node' => 'topic_' . $key,
                    'from_pin' => 'q' . $i,
                    'to_node' => 'ans_' . $key . '_' . $i,
                ];

                $wires[] = [
                    'from_node' => 'ans_' . $key . '_' . $i,
                    'from_pin' => 'out',
                    'to_node' => 'more',
                ];
            }
        }

        return $wires;
    }

    /**
     * The wires.
     *
     * `answer` leaves by two pins: what the bank could address goes on to
     * "anything else", and what it could not goes to the same place — the
     * unanswered question is already recorded, and telling a visitor twice
     * that we cannot help is worse than asking whether they have another.
     *
     * @return array<int, array<string, string>>
     */
    private static function wires(): array
    {
        return [
            ['from_node' => 'greet', 'from_pin' => 'out', 'to_node' => 'ask_name'],
            ['from_node' => 'ask_name', 'from_pin' => 'out', 'to_node' => 'welcome'],
            ['from_node' => 'welcome', 'from_pin' => 'out', 'to_node' => 'ask_email'],
            ['from_node' => 'ask_email', 'from_pin' => 'out', 'to_node' => 'capture'],
            ['from_node' => 'capture', 'from_pin' => 'out', 'to_node' => 'menu'],

            // The three menu options that are not subjects.
            ['from_node' => 'menu', 'from_pin' => 'topics', 'to_node' => 'topics'],
            ['from_node' => 'menu', 'from_pin' => 'human', 'to_node' => 'human'],
            ['from_node' => 'menu', 'from_pin' => 'other', 'to_node' => 'ask_question'],

            // Wanting a person is not the end of the conversation.
            ['from_node' => 'human', 'from_pin' => 'out', 'to_node' => 'more'],

            // The bank's own subjects, all through one wire.
            ['from_node' => 'topics', 'from_pin' => 'topic', 'to_node' => 'answer'],

            ['from_node' => 'ask_question', 'from_pin' => 'out', 'to_node' => 'answer'],
            ['from_node' => 'answer', 'from_pin' => 'answered', 'to_node' => 'more'],
            ['from_node' => 'answer', 'from_pin' => 'unanswered', 'to_node' => 'more'],

            ['from_node' => 'more', 'from_pin' => 'again', 'to_node' => 'ask_question'],
            ['from_node' => 'more', 'from_pin' => 'human', 'to_node' => 'human'],
            ['from_node' => 'more', 'from_pin' => 'done', 'to_node' => 'goodbye'],
        ];
    }
}
