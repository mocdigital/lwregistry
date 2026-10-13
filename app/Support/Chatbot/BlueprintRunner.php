<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use App\Models\ChatbotBlueprint;
use App\Models\ChatbotDocument;
use App\Models\ChatbotLead;
use App\Models\ChatbotNode;
use App\Models\ChatbotPassage;
use App\Models\ChatbotSession;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Walks a visitor through a blueprint.
 *
 * One call, one turn: it is handed whatever the visitor just said and returns
 * everything to show them before the next thing they have to do. A "turn" is
 * therefore usually several messages — a greeting, then a question — because
 * that is how the flow reads to the person on the other end, and making them
 * press something between each one would be pretending the graph is a chat.
 *
 * It stops at the first node that needs an answer (`ask`, `choice`) or at the
 * end. Everything before that runs immediately.
 *
 * A LOOP GUARD is the one thing this cannot do without: an administrator can
 * wire a node back into itself on a canvas, and without a cap that is an
 * infinite loop in a request rather than a mistake somebody can see.
 */
final class BlueprintRunner
{
    /** More steps than any sensible flow, fewer than a hang. */
    private const MAX_STEPS = 40;

    public function __construct(private readonly Responder $knowledge) {}

    /**
     * Advance the conversation.
     *
     * @param  string|null  $said  What the visitor typed, or the pin they pressed
     * @param  User|null  $user  Signed in, if anybody is — the flow does not ask a
     *                           member for a name the account already holds
     * @return array{messages: array<int, array{text: string, answered: bool}>, choices: array<int, array{label: string, pin: string}>, awaiting: string|null, finished: bool, passageIds: array<int, int>}
     */
    public function advance(string $sessionId, ?string $said = null, ?string $pin = null, ?User $user = null): array
    {
        $userId = $user?->id;
        $blueprint = ChatbotBlueprint::live();

        // No blueprint published: the assistant is still the knowledge bank,
        // so a question is answered rather than refused.
        if ( ! $blueprint instanceof ChatbotBlueprint) {
            return $this->withoutBlueprint($said);
        }

        $session = ChatbotSession::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'blueprint_id' => $blueprint->id,
                'current_node' => $blueprint->entryNode()?->key,
                /*
                 * A signed-in member is already known to us. Asking them their
                 * name and email is asking for something we are holding, which
                 * reads as an assistant that has not looked.
                 */
                'data' => self::known($user),
            ],
        );

        // Signed in AFTER the conversation started, or opened it before the
        // page knew who they were.
        if (null !== $user && null === $session->value('name')) {
            foreach (self::known($user) as $key => $value) {
                $session->remember($key, $value);
            }
        }

        /*
         * A blueprint that has been republished since this visitor started
         * would leave them standing on a node that no longer exists. Start
         * them again rather than dropping them into the middle of a flow they
         * have not seen the beginning of.
         */
        if ($session->blueprint_id !== $blueprint->id) {
            $session->forceFill([
                'blueprint_id' => $blueprint->id,
                'current_node' => $blueprint->entryNode()?->key,
                'data' => [],
            ])->save();
        }

        $node = $this->nodeAt($blueprint, $session->current_node);

        if ( ! $node instanceof ChatbotNode) {
            return ['messages' => [], 'choices' => [], 'awaiting' => null, 'finished' => true, 'passageIds' => []];
        }

        $messages = [];

        /*
         * Which passages answered, gathered across the turn. An assistant that
         * cannot show its working is one nobody can correct.
         */
        $passageIds = [];

        // The visitor has just answered the node they were standing on.
        if (null !== $said || null !== $pin) {
            $outcome = $this->accept($node, $session, $said, $pin, $userId);

            if (isset($outcome['error'])) {
                // Rejected — the same question again, with the reason.
                return [
                    'messages' => [['text' => $outcome['error'], 'answered' => true]],
                    'choices' => $this->choicesFor($node, $session),
                    'awaiting' => $node->type,
                    'finished' => false,
                    'passageIds' => [],
                ];
            }

            foreach ($outcome['messages'] ?? [] as $message) {
                $messages[] = $message;
            }

            $passageIds = array_merge($passageIds, $outcome['passageIds'] ?? []);

            // `stay` answers inline and keeps the visitor on the same node, so
            // the menu is offered again — how a related suggestion loops.
            if ( ! ($outcome['stay'] ?? false)) {
                $node = $this->nodeAt($blueprint, $this->nextKey($blueprint, $node, $outcome['pin'] ?? 'out'));
            }
        }

        // Run forward until something needs the visitor.
        $steps = 0;

        while ($node instanceof ChatbotNode && $steps++ < self::MAX_STEPS) {
            /*
             * Already answered, so do not ask again. This is what lets one
             * blueprint serve a guest and a member: the guest is asked their
             * name, and the member — whose name came from their account — walks
             * straight past the question to the part that helps them.
             *
             * Opt out with `always_ask` on a node that must be answered every
             * time, whatever is already known.
             */
            if (
                NodeType::ASK === $node->type
                && ! $node->setting('always_ask', false)
                && filled($session->value((string) $node->setting('field', 'answer')))
            ) {
                $node = $this->nodeAt($blueprint, $this->nextKey($blueprint, $node, 'out'));

                continue;
            }

            if (in_array($node->type, [NodeType::ASK, NodeType::CHOICE], true)) {
                $choices = $this->choicesFor($node, $session);

                /*
                 * When the node has related things to suggest, the bot leads
                 * with them rather than a flat "anything else?" — an answer
                 * followed by "you might also want to know" is a conversation
                 * that goes somewhere, which is what a good assistant does.
                 */
                $text = collect($choices)->contains(fn (array $c): bool => 'suggest' === $c['pin'])
                    ? __('chatbot.follow_up_intro')
                    : $this->fill($node->setting('text', ''), $session);

                $messages[] = ['text' => $text, 'answered' => true];
                $session->forceFill(['current_node' => $node->key])->save();

                return [
                    'messages' => $messages,
                    'choices' => $choices,
                    'awaiting' => $node->type,
                    'finished' => false,
                ];
            }

            if (NodeType::END === $node->type) {
                $messages[] = ['text' => $this->fill($node->setting('text', ''), $session), 'answered' => true];
                $session->forceFill(['current_node' => null])->save();

                return [
                    'messages' => $messages,
                    'choices' => [],
                    'awaiting' => null,
                    'finished' => true,
                    'passageIds' => array_values(array_unique($passageIds)),
                ];
            }

            if (NodeType::SAY === $node->type) {
                /*
                 * "Talk to a person" is not just another line of copy — it
                 * hands the conversation over. The bot says what the handoff
                 * decided (connecting, or nobody is here) and then stops, so it
                 * does not carry on to "anything else" over the top of a
                 * request for a human.
                 */
                if ($this->isHandoff($node)) {
                    $handoff = app(HumanHandoff::class)->request($session);
                    $messages[] = ['text' => $handoff['text'], 'answered' => true];

                    return [
                        'messages' => $messages,
                        'choices' => [],
                        'awaiting' => null,
                        'finished' => false,
                        'handoff' => $handoff['state'],
                        'passageIds' => array_values(array_unique($passageIds)),
                    ];
                }

                $messages[] = ['text' => $this->fill($node->setting('text', ''), $session), 'answered' => true];
            }

            if (NodeType::CAPTURE === $node->type) {
                $this->capture($session, $userId);
            }

            /*
             * The knowledge bank, reached by a wire rather than by somebody
             * typing at it. It answers whatever was last asked — a typed
             * question, or the label of the button that led here — and leaves
             * by the pin that says whether it could.
             */
            if (NodeType::KNOWLEDGE === $node->type) {
                $answer = $this->answerAndConsume($session, (string) $session->value('question', ''));
                $passageIds = array_merge($passageIds, $answer->passageIds);

                /*
                 * Could not answer — so do not answer. Rather than read out a
                 * list of what it DOES know and loop back to "anything else",
                 * the bot hands the question to a person to continue: that is
                 * exactly the moment a human should take over. Online, someone
                 * is fetched; offline, support follows up. Either way the
                 * unanswered question is on record, which is what it was for.
                 */
                if ( ! $answer->answered) {
                    // answered:false throughout, so the question is recorded as
                    // one the bank could not handle — that list is the brief for
                    // the next document to add. The controller marks the turn
                    // answered if any message is, so both lines must be false.
                    $messages[] = ['text' => __('chatbot.escalating'), 'answered' => false];

                    $handoff = app(HumanHandoff::class)->request($session);
                    $messages[] = ['text' => $handoff['text'], 'answered' => false];

                    return [
                        'messages' => $messages,
                        'choices' => [],
                        'awaiting' => null,
                        'finished' => false,
                        'handoff' => $handoff['state'],
                        'passageIds' => array_values(array_unique($passageIds)),
                    ];
                }

                $messages[] = ['text' => $answer->text, 'answered' => true];

                $node = $this->nodeAt($blueprint, $this->nextKey($blueprint, $node, 'answered'));

                continue;
            }

            $node = $this->nodeAt($blueprint, $this->nextKey($blueprint, $node, 'out'));
        }

        // Ran off the end of the wires, or hit the step cap.
        $session->forceFill(['current_node' => null])->save();

        return [
            'messages' => $messages,
            'choices' => [],
            'awaiting' => null,
            'finished' => true,
            'passageIds' => array_values(array_unique($passageIds)),
        ];
    }

    /**
     * Answer a question straight from the knowledge bank.
     *
     * The home screen is a set of actions, not a scripted greeting — so a card
     * or a typed question goes directly to an answer, without walking the
     * name-and-email preamble the guided blueprint opens with. Unanswered
     * questions still escalate to a person, and an answer still offers related
     * things to ask next.
     *
     * @return array{messages: array<int, array{text: string, answered: bool}>, choices: array<int, array{label: string, pin: string}>, awaiting: null, finished: bool, handoff: string|null, passageIds: array<int, int>}
     */
    public function directAnswer(string $sessionId, string $question, ?User $user = null): array
    {
        $blueprint = ChatbotBlueprint::live();

        $session = ChatbotSession::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'blueprint_id' => $blueprint?->id,
                'current_node' => null,
                'data' => self::known($user),
            ],
        );

        // A member who signed in is known; file them as a lead so a question
        // from the home screen still connects to who asked it.
        if (null !== $user && null === $session->value('name')) {
            foreach (self::known($user) as $key => $value) {
                $session->remember($key, $value);
            }
        }

        $this->capture($session, $user?->id);

        $q = mb_trim($question);
        $session->remember('last_question', $q);
        $this->markAsked($session, $q);

        // A typed request for a person — "talk to someone", "connect me to an
        // agent" — connects them to a support agent rather than being answered
        // from the bank.
        if ($this->wantsHuman($q)) {
            $handoff = app(HumanHandoff::class)->request($session);

            return [
                'messages' => [['text' => $handoff['text'], 'answered' => true]],
                'choices' => [],
                'awaiting' => null,
                'finished' => false,
                'handoff' => $handoff['state'],
                'passageIds' => [],
            ];
        }

        $answer = $this->knowledge->answer($q);

        if ( ! $answer->answered) {
            $handoff = app(HumanHandoff::class)->request($session);

            return [
                'messages' => [
                    ['text' => __('chatbot.escalating'), 'answered' => false],
                    ['text' => $handoff['text'], 'answered' => false],
                ],
                'choices' => [],
                'awaiting' => null,
                'finished' => false,
                'handoff' => $handoff['state'],
                'passageIds' => [],
            ];
        }

        $messages = [['text' => $answer->text, 'answered' => true]];

        // Follow the answer with up to three related questions the visitor is
        // likely to want next, offered as quick replies they can tap — each one
        // re-asks that question and is answered from the bank.
        $related = $this->knowledge->related($q, 3);
        $choices = [];

        if ([] !== $related) {
            $messages[] = ['text' => __('chatbot.you_might_also_ask'), 'answered' => true];
            $choices = array_map(
                static fn (string $question): array => ['label' => $question, 'pin' => $question],
                $related,
            );
        }

        return [
            'messages' => $messages,
            'choices' => $choices,
            'awaiting' => null,
            'finished' => false,
            'handoff' => null,
            'passageIds' => $answer->passageIds,
        ];
    }

    /**
     * Phrasings that read as "I want a real person", used to route a typed
     * request straight to the human handoff.
     *
     * Kept to clear multi-word intents, so an ordinary question that merely
     * mentions support — "how do I contact support" — is still answered from
     * the bank rather than fetching a person.
     *
     * @var array<int, string>
     */
    private const HUMAN_REQUESTS = [
        'talk to a person', 'talk to someone', 'talk to a human', 'talk to an agent',
        'talk to a representative', 'talk to a real person', 'talk to support',
        'talk to an admin', 'talk to a live agent', 'talk to a support agent',
        'speak to a person', 'speak to someone', 'speak to a human', 'speak to an agent',
        'speak to a representative', 'speak to a real person', 'speak to support',
        'speak with a person', 'speak with someone', 'speak with a human',
        'speak with an agent', 'speak to a live agent',
        'chat with a person', 'chat with someone', 'chat with a human',
        'chat with an agent', 'chat to a person', 'chat with support',
        'connect me to a person', 'connect me to someone', 'connect me to an agent',
        'connect me to support', 'connect me with a person', 'connect me with an agent',
        'connect me with someone', 'connect me to a human', 'connect with an agent',
        'put me through to',
        'live agent', 'live person', 'live support', 'real person', 'real human',
        'human agent', 'human support', 'customer service', 'customer support',
        'support agent', 'support representative',
        'i want to talk to', 'i need to talk to', 'i want to speak to',
        'i need to speak to', 'reach a person', 'reach an agent', 'reach someone',
        'get me a human', 'want a human', 'need a human', 'contact an agent',
    ];

    /** Does this read as a request to talk to a person rather than a question? */
    private function wantsHuman(string $question): bool
    {
        $normalised = ' ' . mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $question)) . ' ';
        $normalised = (string) preg_replace('/\s+/', ' ', $normalised);

        foreach (self::HUMAN_REQUESTS as $phrase) {
            if (str_contains($normalised, ' ' . $phrase . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask for a person directly, from anywhere in the conversation.
     *
     * The "Talk to a support person" control does not depend on where the flow
     * has got to — a visitor can ask for help at any point — so this hands off
     * without needing a node wired for it. Ensures the session exists first, so
     * a visitor who opens the panel and clicks straight through is still a lead.
     *
     * @return array{messages: array<int, array{text: string, answered: bool}>, choices: array<int, array{label: string, pin: string}>, awaiting: null, finished: bool, handoff: string}
     */
    public function handoff(string $sessionId, ?User $user = null): array
    {
        $blueprint = ChatbotBlueprint::live();

        $session = ChatbotSession::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'blueprint_id' => $blueprint?->id,
                'current_node' => $blueprint?->entryNode()?->key,
                'data' => self::known($user),
            ],
        );

        $result = app(HumanHandoff::class)->request($session);

        return [
            'messages' => [['text' => $result['text'], 'answered' => true]],
            'choices' => [],
            'awaiting' => null,
            'finished' => false,
            'handoff' => $result['state'],
        ];
    }

    /**
     * Put a visitor back at the beginning.
     *
     * The position and the collected answers go; what a signed-in member's
     * account tells us stays, because it was never something they typed. The
     * lead is untouched — somebody wanting to ask a different question does
     * not undo the enquiry they have already made.
     */
    public function restart(string $sessionId, ?User $user = null): void
    {
        $blueprint = ChatbotBlueprint::live();

        ChatbotSession::updateOrCreate(
            ['session_id' => $sessionId],
            [
                'blueprint_id' => $blueprint?->id,
                'current_node' => $blueprint?->entryNode()?->key,
                'data' => self::known($user),
                // Leave the handoff too. A conversation that was escalated is
                // otherwise stuck talking to a person forever — restart has to
                // put the bot back in charge, or "start again" does nothing for
                // exactly the visitor most likely to press it.
                'handoff_state' => null,
                'handoff_requested_at' => null,
                'admin_id' => null,
            ],
        );
    }

    /**
     * What the account already tells us about a signed-in member.
     *
     * @return array<string, string>
     */
    private static function known(?User $user): array
    {
        if (null === $user) {
            return [];
        }

        $name = mb_trim($user->firstname . ' ' . $user->lastname);

        return array_filter([
            // The first name alone: a conversation greets somebody by what
            // they are called, not by what is on their passport.
            'name' => mb_trim((string) $user->firstname) ?: $name,
            'email' => (string) $user->email,
        ]);
    }

    /**
     * Take what the visitor said and work out which pin to leave by.
     *
     * @return array{pin?: string, error?: string, messages?: array<int, array{text: string, answered: bool}>, passageIds?: array<int, int>}
     */
    private function accept(
        ChatbotNode $node,
        ChatbotSession $session,
        ?string $said,
        ?string $pin,
        ?int $userId,
    ): array {
        if (NodeType::CHOICE === $node->type) {
            $pins = $node->pins();

            /*
             * A related suggestion offered at "anything else?". It is answered
             * on the spot and the menu is shown again with fresh suggestions,
             * rather than leaving by a wire — so a visitor can follow a thread
             * of related questions without the flow moving on.
             */
            if ('suggest' === $pin) {
                $q = mb_trim((string) $said);

                if ('' !== $q) {
                    $session->remember('question', $q);
                }

                $answer = $this->answerAndConsume($session, $q);

                return [
                    'stay' => true,
                    'messages' => [['text' => $answer->text, 'answered' => $answer->answered]],
                    'passageIds' => $answer->passageIds,
                ];
            }

            if (null !== $pin && in_array($pin, $pins, true)) {
                /*
                 * A knowledge-sourced option — a topic drawn from the bank — is
                 * NOT in the node's static options: its label is the question,
                 * and the widget sends that back as what was said. A static
                 * option is navigation ("another question", "a person") and
                 * must NOT be recorded as a question, or the open-question node
                 * it leads to is skipped and re-answers a button's label.
                 */
                $isStatic = null !== collect((array) $node->setting('options', []))->firstWhere('pin', $pin);

                if ( ! $isStatic && filled($said)) {
                    $session->remember('question', (string) $said);
                }

                return ['pin' => $pin];
            }

            /*
             * Typed instead of pressing. What they typed is the question — keep
             * it, so the fallback that leads to the knowledge bank answers THIS
             * rather than whatever was asked last.
             */
            if (filled($said)) {
                $session->remember('question', (string) $said);
            }

            return ['pin' => (string) ($node->setting('fallback_pin') ?: ($pins[0] ?? 'out'))];
        }

        if (NodeType::ASK === $node->type) {
            $answer = mb_trim((string) $said);
            $field = (string) $node->setting('field', 'answer');

            if ('' === $answer) {
                // Optional questions may be walked past; the email usually is.
                if ($node->setting('optional', false)) {
                    return ['pin' => 'out'];
                }

                return ['error' => (string) $node->setting('required_message', 'Sorry, I did not catch that.')];
            }

            if ('email' === $node->setting('validate')) {
                $failed = Validator::make(['value' => $answer], ['value' => ['email']])->fails();

                if ($failed) {
                    return ['error' => (string) $node->setting('invalid_message', 'That does not look like an email address.')];
                }
            }

            $session->remember($field, $answer);

            // Capture as we go, so a visitor who leaves after giving a name
            // and an address is still somebody who can be followed up.
            if (in_array($field, ['name', 'email'], true)) {
                $this->capture($session, $userId);
            }

            return ['pin' => 'out'];
        }

        if (NodeType::KNOWLEDGE === $node->type) {
            $answer = $this->answerAndConsume($session, (string) $said);

            return [
                'pin' => $answer->answered ? 'answered' : 'unanswered',
                'messages' => [['text' => $answer->text, 'answered' => $answer->answered]],
                'passageIds' => $answer->passageIds,
            ];
        }

        return ['pin' => 'out'];
    }

    /**
     * The knowledge bank on its own, for before anybody has drawn a flow.
     *
     * @return array{messages: array<int, array{text: string, answered: bool}>, choices: array<int, array{label: string, pin: string}>, awaiting: string|null, finished: bool, passageIds: array<int, int>}
     */
    private function withoutBlueprint(?string $said): array
    {
        if (null === $said || '' === mb_trim($said)) {
            return [
                'messages' => [['text' => __('chatbot.greeting'), 'answered' => true]],
                'choices' => [],
                'awaiting' => NodeType::ASK,
                'finished' => false,
                'passageIds' => [],
            ];
        }

        $answer = $this->knowledge->answer($said);

        return [
            'messages' => [['text' => $answer->text, 'answered' => $answer->answered]],
            'choices' => [],
            'awaiting' => NodeType::ASK,
            'finished' => false,
            'passageIds' => $answer->passageIds,
        ];
    }

    /** Save who we have been talking to. One row per session, updated. */
    private function capture(ChatbotSession $session, ?int $userId): void
    {
        $name = $session->value('name');

        // Nothing to file under. A lead with no name is a row nobody can act on.
        if (null === $name || '' === mb_trim((string) $name)) {
            return;
        }

        ChatbotLead::updateOrCreate(
            ['session_id' => $session->session_id],
            [
                'user_id' => $userId,
                'name' => mb_substr((string) $name, 0, 180),
                'email' => $session->value('email'),
                'first_question' => $session->value('first_question'),
            ],
        );
    }

    /** @param  array<int, string>  $_ */
    /*
     * The node that means "fetch a person". Recognised by a `handoff` flag in
     * its config, or by the key the default blueprint ships it under — so it
     * works on the blueprint already published, which predates the flag, and on
     * one an administrator draws with the flag set deliberately.
     */
    private function isHandoff(ChatbotNode $node): bool
    {
        return (bool) $node->setting('handoff', false) || 'human' === $node->key;
    }

    /*
     * Answer a question and mark it used up.
     *
     * "question" is the pending one to answer; "last_question" is what was just
     * answered, kept so the "anything else?" step can suggest related things.
     * Separating them is what lets the open-question node prompt every time
     * rather than be skipped as already-answered and re-serve the last reply.
     */
    private function answerAndConsume(ChatbotSession $session, string $question): Answer
    {
        $answer = $this->knowledge->answer($question);

        if ('' !== mb_trim($question)) {
            $session->remember('last_question', $question);
            $this->markAsked($session, $question);
        }

        $session->remember('question', '');

        return $answer;
    }

    /**
     * Remember that this conversation has already covered a question, so it is
     * not offered again as a follow-up. Normalised, so the same question typed
     * two different ways still counts as asked; capped, so a long conversation
     * does not carry an unbounded list.
     */
    private function markAsked(ChatbotSession $session, string $question): void
    {
        $key = ChatbotPassage::normalise($question);

        if ('' === $key) {
            return;
        }

        $asked = (array) $session->value('asked', []);
        $asked[] = $key;

        $session->remember('asked', array_slice(array_values(array_unique($asked)), -40));
    }

    /**
     * Related follow-ups the visitor has not already been given.
     *
     * Asks for more than it needs and drops the ones already covered, so the
     * list is fresh every time rather than re-offering what was just clicked —
     * and returns fewer, or none, once a topic is exhausted rather than padding
     * it with repeats.
     *
     * @return array<int, string>
     */
    private function freshSuggestions(ChatbotSession $session, string $last, int $limit): array
    {
        $asked = array_flip((array) $session->value('asked', []));

        $fresh = [];

        foreach ($this->knowledge->related($last, $limit + 8) as $question) {
            if (isset($asked[ChatbotPassage::normalise($question)])) {
                continue;
            }

            $fresh[] = $question;

            if (count($fresh) >= $limit) {
                break;
            }
        }

        return $fresh;
    }

    private function nodeAt(ChatbotBlueprint $blueprint, ?string $key): ?ChatbotNode
    {
        return null === $key ? null : $blueprint->nodes->firstWhere('key', $key);
    }

    private function nextKey(ChatbotBlueprint $blueprint, ChatbotNode $node, string $pin): ?string
    {
        return $blueprint->wires
            ->first(fn ($wire): bool => $wire->from_node === $node->key && $wire->from_pin === $pin)
            ?->to_node;
    }

    /** @return array<int, array{label: string, pin: string}> */
    private function choicesFor(ChatbotNode $node, ?ChatbotSession $session = null): array
    {
        if (NodeType::CHOICE !== $node->type) {
            return [];
        }

        $choices = array_values(array_map(
            static fn (array $option): array => [
                'label' => (string) ($option['label'] ?? ''),
                'pin' => (string) ($option['pin'] ?? ''),
            ],
            (array) $node->setting('options', []),
        ));

        /*
         * Related things to ask next, drawn from what the visitor last asked.
         * At "anything else?" a menu of yes/no/person is a dead end; offering
         * the neighbouring questions somebody who asked that usually has turns
         * it into a conversation that goes somewhere. Recognised by a flag, or
         * by the key the default blueprint ships it under so it works on the
         * blueprint already published.
         */
        if ($session instanceof ChatbotSession && ((bool) $node->setting('suggest_related', false) || 'more' === $node->key)) {
            // What was just answered, not what is pending — the pending one is
            // cleared the moment it is answered.
            $last = (string) $session->value('last_question', '');

            foreach ($this->freshSuggestions($session, $last, 3) as $topic) {
                // A pin the node's static options do not use, so `accept` reads
                // the label as the question rather than looking it up.
                $choices[] = ['label' => $topic, 'pin' => 'suggest'];
            }
        }

        /*
         * Options drawn from the knowledge bank, so the things a visitor is
         * offered are the things somebody has actually written about. An
         * administrator uploading a document adds a conversation point without
         * touching the canvas; deleting one removes it.
         */
        if ('knowledge' === $node->setting('source')) {
            $pin = (string) $node->setting('topic_pin', 'topic');

            $topics = ChatbotDocument::query()
                ->where('status', ChatbotDocument::STATUS_READY)
                ->orderByDesc('passage_count')
                ->take((int) $node->setting('topic_limit', 4))
                ->pluck('title')
                // The prefix marks a document as one the application maintains;
                // it is not something to show a visitor.
                ->map(static fn (string $title): string => (string) preg_replace('/^Registry:\s*/', '', $title));

            foreach ($topics as $topic) {
                $choices[] = ['label' => $topic, 'pin' => $pin];
            }
        }

        return $choices;
    }

    /**
     * Put what has been collected into what is about to be said.
     *
     * "Welcome, :name." is how a flow greets somebody by name without every
     * node type needing to know about names.
     */
    private function fill(string $text, ChatbotSession $session): string
    {
        foreach (($session->data ?? []) as $key => $value) {
            if (is_scalar($value)) {
                $text = str_replace(':' . $key, (string) $value, $text);
            }
        }

        // Any placeholder nobody filled is removed rather than shown raw.
        return mb_trim((string) preg_replace('/:[a-z_]+/', '', $text));
    }
}
