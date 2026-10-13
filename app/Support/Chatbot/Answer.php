<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

/**
 * What the assistant says back, and what it based it on.
 *
 * The passage ids travel with the text so an answer can be checked against its
 * source. An assistant that cannot show its working is one nobody can correct.
 */
final class Answer
{
    /**
     * @param  array<int, int>  $passageIds
     */
    public function __construct(
        public readonly string $text,
        public readonly bool $answered,
        public readonly array $passageIds = [],
    ) {}

    /**
     * Nothing in the bank addressed the question.
     *
     * Deliberately not a guess. A sales assistant that invents an answer about
     * price or eligibility does more damage than one that says it will find
     * out — and the unanswered question is the useful signal anyway.
     */
    public static function none(string $text): self
    {
        return new self($text, false);
    }
}
