<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

/**
 * Cut a document into passages a question can be answered with.
 *
 * Paragraphs first, because that is where a writer put the boundaries. A
 * paragraph too long to be an answer is split at sentence ends; one too short
 * to say anything is joined to the next. Fixed-size chunking would cut through
 * the middle of the sentence that held the answer.
 */
final class PassageSplitter
{
    /** Long enough to answer something, short enough to read in a chat bubble. */
    private const MAX_CHARACTERS = 900;

    /** Below this a passage is a heading or a stray line, not an answer. */
    private const MIN_CHARACTERS = 80;

    /**
     * @return array<int, string>
     */
    public function split(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [];

        $passages = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = mb_trim(preg_replace('/\s+/', ' ', $paragraph) ?? $paragraph);

            if ('' === $paragraph) {
                continue;
            }

            foreach ($this->fit($paragraph) as $piece) {
                /*
                 * A heading carries the subject of what follows it ("Pricing",
                 * "Registration fees"), so joining it to the next paragraph
                 * keeps the words a question is most likely to use.
                 */
                if (mb_strlen($buffer) > 0 && mb_strlen($buffer) < self::MIN_CHARACTERS) {
                    $buffer .= ' ' . $piece;
                } else {
                    if ('' !== $buffer) {
                        $passages[] = $buffer;
                    }

                    $buffer = $piece;
                }

                if (mb_strlen($buffer) >= self::MAX_CHARACTERS) {
                    $passages[] = $buffer;
                    $buffer = '';
                }
            }
        }

        if ('' !== mb_trim($buffer)) {
            $passages[] = $buffer;
        }

        return array_values(array_filter($passages, fn (string $p): bool => mb_strlen(mb_trim($p)) > 0));
    }

    /**
     * Break an over-long paragraph at sentence ends.
     *
     * A cut mid-sentence produces an answer that stops in the middle of a
     * word, which reads as broken rather than as brief.
     *
     * @return array<int, string>
     */
    private function fit(string $paragraph): array
    {
        if (mb_strlen($paragraph) <= self::MAX_CHARACTERS) {
            return [$paragraph];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph) ?: [$paragraph];

        $pieces = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if ('' !== $current && mb_strlen($current) + mb_strlen($sentence) + 1 > self::MAX_CHARACTERS) {
                $pieces[] = $current;
                $current = '';
            }

            // A single sentence longer than the limit is left whole: cutting
            // it would break the one thing this method exists to protect.
            $current = '' === $current ? $sentence : $current . ' ' . $sentence;
        }

        if ('' !== $current) {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
