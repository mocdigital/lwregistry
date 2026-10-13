<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use App\Models\ChatbotPassage;
use Illuminate\Support\Collection;

/**
 * Answer from the uploaded documents, and only from them.
 *
 * Retrieval, not generation: the reply is the part of a document that
 * addresses the question. That is a deliberate choice — a sales assistant that
 * invents an answer about price or eligibility does more damage than one that
 * says it will find out — and it means no question and no document ever leaves
 * this server, which is the rule the self-hosted translator already follows.
 *
 * What "retrieval" means here has been the weak part. Matching substrings
 * meant "fees" missed "fee", "registering" missed "registration", and one
 * transposed letter missed everything; the reply was then the whole passage,
 * however much of it was beside the point. Four things address that:
 *
 *   Vocabulary   stems and expands the question, so a word and its plural, and
 *                the four ways people say "cost", all find each other.
 *   Rarity       weights a term by how few passages contain it, so a word that
 *                appears in every document decides nothing.
 *   Phrases      rewards passages carrying the question's word PAIRS, which is
 *                what separates "registration fee" from a page mentioning both
 *                words a paragraph apart.
 *   Sentences    returns the part of the passage that actually answers, rather
 *                than eight hundred characters around it.
 *
 * Below the threshold it suggests what it does know about instead of saying
 * no — a visitor who asked the wrong question is better served by three
 * subjects than by a refusal.
 */
final class KnowledgeBaseResponder implements Responder
{
    /*
     * Below this there is nothing worth showing — no passage shares enough with
     * the question to be about it — and only then does the assistant say it
     * cannot help. Deliberately low: a question the bank half-covers is better
     * answered with a hedge than handed off, which is what "the bot escalated
     * something it knew" was.
     */
    private const MINIMUM_SCORE = 0.8;

    /*
     * Below this the match is real but loose, so the answer is offered with a
     * stronger "I'm not certain I understood" rather than stated plainly.
     */
    private const TENTATIVE_SCORE = 2.0;

    /** Above this it is confident enough to answer without hedging. */
    private const CONFIDENT_SCORE = 5.0;

    /** Greetings and thanks, which are not questions for the bank. */
    private const SMALL_TALK = [
        'hi', 'hey', 'hello', 'yo', 'hiya', 'morning', 'afternoon', 'evening',
        'thanks', 'thank', 'thankyou', 'cheers', 'ok', 'okay', 'great', 'cool',
        'bye', 'goodbye', 'later',
        // Acknowledgements and one-word replies. On their own they are not
        // questions, so answering "I couldn't find that" — or worse, fetching a
        // person — for someone typing "yes" reads as a bot that is not
        // listening. A friendly nudge to ask something is the right reply.
        'yes', 'yeah', 'yep', 'yup', 'no', 'nope', 'nah', 'sure', 'fine',
        'good', 'nice', 'k', 'kk', 'lol', 'haha', 'hmm', 'oh', 'wow', 'nvm',
    ];

    /*
     * The semantic tier. When the embedder is on, meaning carries most of the
     * weight and the word overlap anchors it; the cosine thresholds decide when
     * a meaning-only match is worth showing and how surely.
     */
    private const SEM_WEIGHT = 0.87;

    /*
     * Cautious on a meaning-only match: below MINIMUM it is dropped (a nearest
     * passage that is not actually about the question is worse than a hand-off),
     * between MINIMUM and CONFIDENT it is hedged, and only at CONFIDENT is it
     * stated plainly. A question the bank has no real answer for should escalate
     * rather than assert the closest thing it holds.
     */
    private const SEM_MINIMUM = 0.42;

    private const SEM_TENTATIVE = 0.50;

    private const SEM_CONFIDENT = 0.60;

    public function __construct(private readonly Embedder $embedder) {}

    public function answer(string $question): Answer
    {
        $asked = mb_trim($question);

        if ('' === $asked) {
            return Answer::none(__('chatbot.ask_something_specific'));
        }

        // "Hi" and "thanks" are not questions, and answering them from a
        // document about fees is how an assistant reads as a machine.
        if ($this->isSmallTalk($asked)) {
            return new Answer(__('chatbot.small_talk_reply'), true);
        }

        /*
         * Two sets, and the difference matters. `$asked` gives the words
         * actually typed; `$terms` adds their synonyms.
         *
         * Matching uses the expanded set, but BREADTH is measured against the
         * typed words — expanding "verification" into seven related terms and
         * then scoring "one of seven matched" punished the question for the
         * assistant's own helpfulness.
         */
        $base = Vocabulary::stems($asked);
        $terms = Vocabulary::terms($asked);

        // Kept unstemmed for the typo check: a misspelling is one edit from
        // the word as WRITTEN, and stemming either side moves them apart.
        $raw = Vocabulary::rawWords($asked);

        if ([] === $terms) {
            return Answer::none(__('chatbot.ask_something_specific'));
        }

        /*
         * The lexical pass: passages that share words with the question, each
         * given the term-overlap score. This is the safety net — everything the
         * assistant answered before the model arrived, it still answers.
         *
         * @var array<int, float> $lexScores  passage id => lexical score
         * @var array<int, ChatbotPassage> $pool  passage id => passage
         */
        $lexScores = [];
        $pool = [];
        $candidates = $this->candidates($terms);

        if ($candidates->isNotEmpty()) {
            $rarity = $this->rarity($terms, $candidates);

            foreach ($candidates as $passage) {
                $lexScores[$passage->id] = $this->score($passage, $terms, $base, $raw, $asked, $rarity);
                $pool[$passage->id] = $passage;
            }
        }

        $bestLex = [] === $lexScores ? 0.0 : max($lexScores);

        /*
         * The semantic pass: how close each passage is in MEANING, from the
         * embedding model. Empty when the model is off, and then everything
         * below is exactly the lexical behaviour as before.
         *
         * @var array<int, float> $semScores  passage id => cosine similarity
         */
        $semScores = $this->semanticScores($asked);
        $bestSem = [] === $semScores ? 0.0 : max($semScores);

        // Pull the closest-in-meaning passages into the pool even when they
        // shared no word with the question — this is what answers something
        // phrased in words the bank never used.
        if ([] !== $semScores) {
            arsort($semScores);
            $missing = [];

            foreach (array_slice(array_keys($semScores), 0, 8) as $id) {
                if (! isset($pool[$id])) {
                    $missing[] = $id;
                }
            }

            if ([] !== $missing) {
                foreach (ChatbotPassage::query()->whereIn('id', $missing)->get() as $passage) {
                    $pool[$passage->id] = $passage;
                }
            }
        }

        // Nothing worth showing: neither a shared-word match nor a meaning one.
        if ([] === $pool || ($bestLex < self::MINIMUM_SCORE && $bestSem < self::SEM_MINIMUM)) {
            return $this->nothingFound();
        }

        /*
         * Blend the two signals. When the model is on, meaning leads and the
         * word overlap anchors it and breaks ties; when it is off, the score is
         * purely lexical, unchanged from before.
         */
        $lexDivisor = $bestLex > 0.0 ? $bestLex : 1.0;
        $scored = [];

        foreach ($pool as $id => $passage) {
            $lex = $lexScores[$id] ?? 0.0;
            $cos = max(0.0, $semScores[$id] ?? 0.0);
            $combined = [] !== $semScores
                ? self::SEM_WEIGHT * $cos + (1.0 - self::SEM_WEIGHT) * ($lex / $lexDivisor)
                : $lex / $lexDivisor;

            $scored[] = ['passage' => $passage, 'combined' => $combined, 'lex' => $lex, 'cos' => $cos];
        }

        usort($scored, static fn (array $a, array $b): int => $b['combined'] <=> $a['combined']);

        $best = $scored[0];

        // Two passages at most, and only a second if it is close to the first —
        // a page of results is not a reply.
        $useful = array_slice(
            array_filter($scored, static fn (array $row): bool => $row['combined'] >= $best['combined'] * 0.6),
            0,
            2,
        );

        $text = collect($useful)
            ->map(fn (array $row): string => $this->extract($row['passage']->content, $terms))
            ->filter()
            ->unique()
            ->implode("\n\n");

        /*
         * Three registers, by how sure it is — read off whichever signal is
         * strong. Plain when confident; a light hedge when it cleared the bar
         * but not by much; a stronger one when the match is loose.
         */
        $confident = $best['lex'] >= self::CONFIDENT_SCORE || $best['cos'] >= self::SEM_CONFIDENT;
        $loose = $best['lex'] < self::TENTATIVE_SCORE && $best['cos'] < self::SEM_TENTATIVE;

        if (! $confident && $loose) {
            $text = __('chatbot.tentative_match_prefix') . "\n\n" . $text;
        } elseif (! $confident) {
            $text = __('chatbot.best_match_prefix') . "\n\n" . $text;
        }

        return new Answer(
            $text,
            true,
            array_map(static fn (array $row): int => (int) $row['passage']->id, $useful),
        );
    }

    /**
     * Every embedded passage's closeness in meaning to the question.
     *
     * Empty — and so a no-op — when the embedder is off or the question cannot
     * be embedded, which is what makes the whole semantic path optional. The
     * vectors are read once and compared in memory; at this bank's size that is
     * a few milliseconds, and there are far fewer questions than page views.
     *
     * @return array<int, float>  passage id => cosine similarity
     */
    private function semanticScores(string $question): array
    {
        if (! $this->embedder->available()) {
            return [];
        }

        $queryVector = $this->embedder->embedOne($question);
        $queryNorm = null === $queryVector ? 0.0 : $this->magnitude($queryVector);

        if (null === $queryVector || $queryNorm <= 0.0) {
            return [];
        }

        $scores = [];

        ChatbotPassage::query()
            ->whereNotNull('embedding')
            ->select(['id', 'embedding'])
            ->chunk(1000, function ($rows) use ($queryVector, $queryNorm, &$scores): void {
                foreach ($rows as $row) {
                    $vector = json_decode((string) $row->embedding, true);

                    if (is_array($vector) && [] !== $vector) {
                        $scores[(int) $row->id] = $this->cosine($queryVector, $queryNorm, $vector);
                    }
                }
            });

        return $scores;
    }

    /**
     * @param  array<int, float>  $query
     * @param  array<int, float>  $vector
     */
    private function cosine(array $query, float $queryNorm, array $vector): float
    {
        $dot = 0.0;
        $vectorNorm = 0.0;

        foreach ($vector as $i => $value) {
            $value = (float) $value;
            $dot += ($query[$i] ?? 0.0) * $value;
            $vectorNorm += $value * $value;
        }

        $vectorNorm = sqrt($vectorNorm);

        return $vectorNorm > 0.0 ? $dot / ($queryNorm * $vectorNorm) : 0.0;
    }

    /** @param  array<int, float>  $vector */
    private function magnitude(array $vector): float
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += (float) $value * (float) $value;
        }

        return sqrt($sum);
    }

    /**
     * A few real questions to ask next, related to what was just asked.
     *
     * Drawn from the curated conversation questions — actual, well-phrased
     * questions — not from snippets of answer text, which read as statements
     * and repeated each other. Each candidate is scored by how many of its
     * words the question shares (synonyms counted, so "cost" meets "fee"); the
     * most-overlapping distinct ones win, and the question just asked is left
     * out. Empty when nothing genuinely overlaps, because an unrelated
     * suggestion is worse than none.
     *
     * @return array<int, string>
     */
    public function related(string $question, int $limit = 3): array
    {
        $asked = mb_trim($question);

        if ('' === $asked || $this->isSmallTalk($asked)) {
            return [];
        }

        // The asked side is expanded with synonyms, so a question about "fees"
        // can suggest one phrased around "cost" or "price".
        $wanted = array_flip(Vocabulary::terms($asked));

        if ([] === $wanted) {
            return [];
        }

        $askedNorm = ChatbotPassage::normalise($asked);

        // Score every candidate by word overlap, and each topic by the overlap
        // of all its questions together. The topic the question belongs to
        // scores highest, which is what lets its siblings — the genuinely
        // related follow-ups — outrank one-word matches scattered elsewhere.
        $topicScores = [];
        $rows = [];

        foreach (ConversationTopics::all() as $key => $topic) {
            $topicScores[$key] = 0;

            foreach ($topic['questions'] as $item) {
                $candidate = (string) ($item['q'] ?? '');

                if ('' === $candidate) {
                    continue;
                }

                // Overlap on the candidate's own words, not its synonyms — a
                // question is about what it says, and expanding it too would
                // make everything look related to everything.
                $overlap = 0;

                foreach (Vocabulary::stems($candidate) as $stem) {
                    if (isset($wanted[$stem])) {
                        $overlap++;
                    }
                }

                $topicScores[$key] += $overlap;

                // The question just asked is not a follow-up to itself.
                if (ChatbotPassage::normalise($candidate) === $askedNorm) {
                    continue;
                }

                $rows[$candidate] = ['topic' => $key, 'overlap' => $overlap];
            }
        }

        // The home topic: the one whose questions, taken together, overlap the
        // asked question most. Its siblings are related even when they share no
        // word with it, so they are offered ahead of scattered one-word hits.
        arsort($topicScores);
        $home = array_key_first($topicScores);
        $homeStrong = ($topicScores[$home] ?? 0) > 0;

        $candidates = [];

        foreach ($rows as $candidate => $row) {
            $score = $row['overlap'];

            if ($homeStrong && $row['topic'] === $home) {
                $score += 10;
            }

            if ($score > 0) {
                $candidates[$candidate] = $score;
            }
        }

        if ([] === $candidates) {
            return [];
        }

        // Most relevant first; a stable order for ties so the list does not
        // reshuffle between turns.
        arsort($candidates);

        return array_slice(array_keys($candidates), 0, $limit);
    }

    /**
     * Nothing matched, so offer what there IS.
     *
     * A visitor who asked the wrong question is better served by three
     * subjects than by a refusal, and the subjects come from the documents
     * themselves so the list cannot promise something nobody uploaded.
     */
    private function nothingFound(): Answer
    {
        $titles = \App\Models\ChatbotDocument::query()
            ->where('status', \App\Models\ChatbotDocument::STATUS_READY)
            ->orderByDesc('passage_count')
            ->take(4)
            ->pluck('title');

        if ($titles->isEmpty()) {
            return Answer::none(__('chatbot.nothing_on_that_yet'));
        }

        return Answer::none(
            __('chatbot.nothing_on_that_but') . "\n\n• " . $titles->implode("\n• "),
        );
    }

    /**
     * Passages worth scoring.
     *
     * A LIKE per term rather than full-text search: FTS is spelled differently
     * on SQLite and MySQL and needs a schema feature on each, and this runs
     * against a company's own handful of documents rather than a corpus.
     *
     * The stem is what is matched, so "registration" is found by "register" —
     * `%stem%` catches both the word and its inflections.
     *
     * @param  array<int, string>  $terms
     * @return Collection<int, ChatbotPassage>
     */
    private function candidates(array $terms): Collection
    {
        $found = ChatbotPassage::query()
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    // A leading space: the term must START a word. Without it
                    // "tractor" matches inside "contractors", and a question
                    // about farm machinery is answered from a paragraph about
                    // hiring builders.
                    $query->orWhere('searchable', 'like', '% ' . $term . '%')
                        ->orWhere('searchable', 'like', $term . '%');
                }
            })
            ->take(300)
            ->get();

        if ($found->isNotEmpty()) {
            return $found;
        }

        /*
         * A second, wider net for a misspelling.
         *
         * The near-match scoring below could never fire: a typo finds no
         * candidates, and a passage that is not a candidate is never scored.
         * "registraton" therefore fell through to "I have nothing on that"
         * while the answer sat in the bank. Matching on the first few letters
         * brings the plausible passages in, and the scoring then decides
         * whether the word was really a typo for one of theirs.
         */
        $prefixes = collect($terms)
            ->filter(static fn (string $term): bool => mb_strlen($term) >= 6)
            ->map(static fn (string $term): string => mb_substr($term, 0, 5));

        if ($prefixes->isEmpty()) {
            return $found;
        }

        return ChatbotPassage::query()
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('searchable', 'like', '% ' . $prefix . '%')
                        ->orWhere('searchable', 'like', $prefix . '%');
                }
            })
            ->take(300)
            ->get();
    }

    /**
     * How rare each term is across the passages that matched.
     *
     * A word in every passage separates nothing; a word in one separates it
     * from everything else. Without this, "business" — which is in every
     * document a business registry owns — counts as heavily as "refund".
     *
     * @param  array<int, string>  $terms
     * @param  Collection<int, ChatbotPassage>  $candidates
     * @return array<string, float>
     */
    private function rarity(array $terms, Collection $candidates): array
    {
        $total = max(1, $candidates->count());
        $rarity = [];

        foreach ($terms as $term) {
            $containing = $candidates->filter(
                fn (ChatbotPassage $p): bool => str_contains(' ' . $p->searchable, ' ' . $term),
            )->count();

            /*
             * Floored at 1. On a bank of three documents every term is in most
             * passages, so the raw figure crushes every score towards zero and
             * nothing clears the threshold — rarity should separate terms, not
             * decide whether there is an answer at all.
             */
            $rarity[$term] = max(1.0, log(1 + ($total / max(1, $containing))) + 1);
        }

        return $rarity;
    }

    /**
     * How well a passage answers the question.
     *
     * @param  array<int, string>  $terms  The question's words and their synonyms
     * @param  array<int, string>  $base  Only the words actually typed
     * @param  array<int, string>  $raw  Those words unstemmed, for typo matching
     * @param  array<string, float>  $rarity
     */
    private function score(
        ChatbotPassage $passage,
        array $terms,
        array $base,
        array $raw,
        string $asked,
        array $rarity,
    ): float {
        /*
         * Padded, so a term at the very start of a passage still counts as
         * starting a word — every occurrence check below looks for a leading
         * space.
         */
        $haystack = ' ' . $passage->searchable;
        // Unstemmed, because that is what a misspelling is one edit away from.
        $words = Vocabulary::rawWords($passage->content);

        $score = 0.0;
        $matched = 0;

        foreach ($terms as $term) {
            $occurrences = mb_substr_count($haystack, ' ' . $term);

            if (0 === $occurrences) {
                continue;
            }

            $matched++;

            // Diminishing: the second mention of a word says much less than
            // the first, and the tenth says nothing at all.
            $score += (1 + log($occurrences)) * ($rarity[$term] ?? 1) * $this->weight($term);
        }

        /*
         * Breadth over repetition: a passage matching several of the words
         * that were TYPED beats one repeating a single term, which is how a
         * glossary entry would otherwise win every question.
         *
         * Against the typed words, not the expanded set — see above.
         */
        $typedMatches = collect($base)->filter(
            static fn (string $word): bool => str_contains($haystack, ' ' . $word),
        )->count();

        $score *= 1 + ($typedMatches / max(count($base), 1));

        /*
         * Coverage, once breadth has counted: a passage that contains EVERY
         * word the visitor typed is answering that question, not merely sharing
         * a word with it. This is what lets a curated Q&A pair — whose text is
         * the question and its answer — win over a glossary entry that repeats
         * one rare term the question happens to use. Only when the question is
         * more than a single word, or "what is registry" would boost every
         * definition that names it.
         */
        if (count($base) >= 2 && $typedMatches === count($base)) {
            $score *= 1.6;
        }

        /*
         * A misspelling, on the raw words of both sides — stemming either side
         * moves a typo away from the word it is one edit from.
         *
         * Scored like the word it is a misspelling OF, discounted, rather than
         * as a flat bonus. A lump sum fired on the first similar word in any
         * passage and then decided the ranking on its own: "verifcation"
         * returned the fees paragraph, which mentions verification in passing,
         * over the document about verification. Counting occurrences lets the
         * passage that is actually about the subject win on its own merits.
         */
        foreach ($raw as $typed) {
            $hits = 0;

            foreach ($words as $word) {
                if (Vocabulary::isNearMatch($typed, $word)) {
                    $hits += mb_substr_count($haystack, ' ' . $word);
                }
            }

            if ($hits > 0) {
                $score += (1 + log($hits)) * $this->weight($typed) * 0.8;
            }
        }

        return $score + $this->phraseBonus($haystack, $asked);
    }

    /**
     * Reward a passage that carries the question's word PAIRS.
     *
     * "How much is the registration fee" and a passage mentioning
     * registration in one paragraph and fees in another share every term. The
     * one that says "registration fee" is answering the question; this is what
     * tells them apart.
     */
    private function phraseBonus(string $haystack, string $asked): float
    {
        $words = Vocabulary::stems($asked);
        $bonus = 0.0;

        for ($i = 0; $i < count($words) - 1; $i++) {
            if (str_contains($haystack, ' ' . $words[$i] . ' ' . $words[$i + 1])) {
                $bonus += 2.0;
            }
        }

        return $bonus;
    }

    /**
     * The part of a passage that answers, rather than all of it.
     *
     * A passage is up to nine hundred characters; the answer is usually one or
     * two sentences of it. Returning the lot buries what was asked for, which
     * is the same failure as returning nothing.
     *
     * @param  array<int, string>  $terms
     */
    private function extract(string $content, array $terms): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $content) ?: [$content];

        // Short enough already: cutting it up would only lose context.
        if (count($sentences) <= 3) {
            return mb_trim($content);
        }

        $ranked = [];

        foreach ($sentences as $index => $sentence) {
            $searchable = ChatbotPassage::normalise($sentence);
            $hits = 0;

            foreach ($terms as $term) {
                if (str_contains($searchable, $term)) {
                    $hits++;
                }
            }

            $ranked[$index] = $hits;
        }

        arsort($ranked);
        $best = (int) array_key_first($ranked);

        /*
         * The best sentence with its neighbours, in the order they were
         * written. A sentence lifted alone often starts with "It" or "This"
         * and refers to something the visitor cannot see.
         */
        $from = max(0, $best - 1);
        $slice = array_slice($sentences, $from, 3);

        return mb_trim(implode(' ', $slice));
    }

    private function isSmallTalk(string $asked): bool
    {
        $words = preg_split('/\s+/', ChatbotPassage::normalise($asked)) ?: [];

        // Only when the WHOLE message is pleasantries: "hi, what does it cost"
        // is a question with a greeting stuck on the front.
        return [] !== $words && collect($words)->every(
            static fn (string $word): bool => in_array($word, self::SMALL_TALK, true),
        );
    }

    /** Longer words are more specific, and specificity is what we are after. */
    private function weight(string $term): float
    {
        return max(1.0, min(mb_strlen($term) / 4, 2.5));
    }
}
