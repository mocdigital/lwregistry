<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

/**
 * Whatever answers a visitor's question.
 *
 * An interface with one implementation today, for the same reason the
 * translator has one: the decision about WHAT answers — the knowledge bank as
 * it stands, or a language model reading the same passages later — should be a
 * binding in a service provider, not something every caller knows about.
 */
interface Responder
{
    public function answer(string $question): Answer;

    /**
     * A few things related to what was asked, to offer as next steps.
     *
     * Empty when nothing is close enough — a suggestion that is not actually
     * related is worse than none.
     *
     * @return array<int, string>
     */
    public function related(string $question, int $limit = 3): array;
}
