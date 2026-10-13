<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

/**
 * Turning what somebody typed into terms worth matching on.
 *
 * The first version matched substrings, which meant "fees" did not find "fee",
 * "registering" did not find "registration", and one transposed letter found
 * nothing at all. Most questions a visitor asks are one of those three cases,
 * so the assistant looked far less capable than the material behind it.
 */
final class Vocabulary
{
    /**
     * Words too common to say anything about what a passage is about.
     *
     * @var array<int, string>
     */
    private const STOP_WORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'for', 'with',
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'it', 'its', 'that', 'this',
        'these', 'those', 'i', 'you', 'we', 'they', 'he', 'she', 'do', 'does', 'did',
        'can', 'could', 'would', 'should', 'will', 'shall', 'may', 'might', 'must',
        'how', 'what', 'when', 'where', 'who', 'why', 'which', 'my', 'your', 'our',
        'me', 'us', 'them', 'at', 'as', 'by', 'from', 'if', 'so', 'than', 'then',
        'there', 'here', 'about', 'any', 'all', 'get', 'got', 'have', 'has', 'had',
        'please', 'tell', 'know', 'want', 'need', 'like', 'much', 'many', 'some',
    ];

    /**
     * Words that mean the same thing to somebody asking a question.
     *
     * Expanded both ways: asking about "price" finds a passage that says "fee",
     * and asking about "fee" finds one that says "cost". Written as groups
     * rather than as pairs, because the moment there are three words for one
     * idea the pairs stop agreeing with each other.
     *
     * Deliberately about THIS registry. A general thesaurus would match on
     * words nobody here uses and drag in passages about nothing.
     *
     * @var array<int, array<int, string>>
     */
    private const SYNONYMS = [
        ['fee', 'fees', 'cost', 'costs', 'price', 'pricing', 'charge', 'charges', 'payment', 'pay'],
        ['register', 'registration', 'signup', 'join', 'joining', 'enrol', 'enrolment', 'apply', 'application'],
        ['verify', 'verification', 'verified', 'vetting', 'approve', 'approval', 'approved'],
        ['document', 'documents', 'paperwork', 'certificate', 'certification', 'papers'],
        ['member', 'membership', 'members'],
        ['business', 'company', 'organisation', 'organization', 'sbo', 'firm'],
        ['espees', 'espee'],
        ['refund', 'refunds', 'refundable', 'cancel', 'cancellation'],
        ['renew', 'renewal', 'renewals', 'expiry', 'expire', 'expires'],
        ['seal', 'badge', 'emblem'],
        ['directory', 'listing', 'listings', 'profile'],
        ['bid', 'bids', 'bidding', 'tender', 'tenders', 'quote', 'quotes', 'rfq', 'procurement'],
        ['long', 'duration', 'time', 'timeline', 'takes', 'quickly', 'fast'],
        ['contact', 'support', 'help', 'reach'],
    ];

    /**
     * The words in a question worth matching on, with their synonyms.
     *
     * @return array<int, string>
     */
    public static function terms(string $text): array
    {
        $words = self::words($text);
        $terms = [];

        foreach ($words as $word) {
            $terms[] = $word;

            foreach (self::SYNONYMS as $group) {
                if (in_array($word, $group, true)) {
                    $terms = array_merge($terms, $group);

                    break;
                }
            }
        }

        return array_values(array_unique(array_map(self::stem(...), $terms)));
    }

    /**
     * The meaningful words of a piece of text, stemmed, without expansion.
     *
     * Used for the passage side: a passage should not be indexed under every
     * synonym of every word it contains, or a document about fees matches a
     * question about payment terms simply for sharing one group.
     *
     * @return array<int, string>
     */
    public static function stems(string $text): array
    {
        return array_values(array_unique(array_map(self::stem(...), self::words($text))));
    }

    /**
     * Cut a word back to the part that carries its meaning.
     *
     * A deliberately small suffix stripper rather than a full Porter stemmer:
     * the four endings below are the ones that separate a question from the
     * passage answering it in practice, and every additional rule is another
     * chance to conflate two words that are not the same.
     */
    public static function stem(string $word): string
    {
        // Short words are usually already the stem, and cutting them produces
        // fragments that match everything.
        if (mb_strlen($word) <= 4) {
            return $word;
        }

        foreach (['ations', 'ation', 'ing', 'ies', 'es', 's'] as $suffix) {
            if (str_ends_with($word, $suffix) && mb_strlen($word) - mb_strlen($suffix) >= 3) {
                $stem = mb_substr($word, 0, -mb_strlen($suffix));

                // "ies" -> "y": companies becomes company, not compan.
                return 'ies' === $suffix ? $stem . 'y' : $stem;
            }
        }

        return $word;
    }

    /**
     * Whether two terms are the same word typed badly.
     *
     * Only for terms long enough that a single edit is more likely to be a
     * typo than a different word — "fee" and "few" are one edit apart and mean
     * nothing like each other.
     */
    public static function isNearMatch(string $a, string $b): bool
    {
        if (mb_strlen($a) < 6 || mb_strlen($b) < 6) {
            return false;
        }

        return levenshtein($a, $b) <= 1;
    }

    /**
     * The meaningful words of a text, unstemmed.
     *
     * What a near-match is compared against. Stemming first defeats the point:
     * a typo breaks the suffix the stemmer looks for, so "registraton" stays
     * whole while "registration" becomes "registr" — four edits apart, and the
     * two are one edit apart as written.
     *
     * @return array<int, string>
     */
    public static function rawWords(string $text): array
    {
        return array_values(array_unique(self::words($text)));
    }

    /**
     * @return array<int, string>
     */
    private static function words(string $text): array
    {
        $normalised = \App\Models\ChatbotPassage::normalise($text);
        $words = preg_split('/\s+/', $normalised) ?: [];

        return array_values(array_filter(
            $words,
            static fn (string $w): bool => mb_strlen($w) > 2 && ! in_array($w, self::STOP_WORDS, true),
        ));
    }
}
