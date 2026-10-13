<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use App\Models\ChatbotDocument;
use App\Models\ChatbotPassage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The curated question-and-answer library, written into the knowledge bank.
 *
 * Two files of call/response pairs — one on how the platform describes itself,
 * one on how to actually do things on it — hand-written so the assistant
 * answers in a natural voice rather than by reading a paragraph out of a PDF.
 * Together they are the difference between a bot that retrieves documents and
 * one that sounds like it knows the place.
 *
 * Each pair becomes its OWN passage, not a paragraph in a shared document: the
 * question carries the words a visitor will actually type, which is what makes
 * the answer findable, and keeping them separate means the responder returns
 * the one answer that fits rather than a wall of the six around it.
 *
 * They are ordinary documents once written — an administrator sees them in
 * Admin > Sales Assistant and can retire any of them. `refresh()` rewrites only
 * the two it owns, matched by their title prefix, so nothing anyone else added
 * is touched.
 */
final class TrainingLibrary
{
    /** Marks a document as this class's to rewrite. */
    private const PREFIX = 'Q&A: ';

    /*
     * Boilerplate about the wider Loveworld/Ministry-of-Commerce ecosystem, not
     * the registry itself. Hundreds of "X is a department listed by the Ministry
     * of Commerce" and "Y is presented on the Ministry of Commerce website"
     * pairs were in the training data, and being short and keyword-dense they
     * surfaced for unrelated questions — "is it free" answered with the Free
     * Trade Zone. A platform support assistant answers about the platform, so
     * these are dropped at import; anything substantive about the registry
     * stays.
     */
    private const OFF_TOPIC = [
        'listed by the ministry of commerce',
        'presented on the ministry of commerce website',
    ];

    /**
     * The files, and the document each is written as.
     *
     * @var array<string, string>
     */
    private const FILES = [
        'lwcommerce_chatbot_520_call_response.json' => 'About LWCommerce and the Registry',
        'lwcommerce_action_guidance_chatbot.json' => 'How to do things on the platform',
    ];

    /** @return int the number of pairs written */
    public static function refresh(): int
    {
        $written = 0;

        foreach (self::FILES as $file => $title) {
            $written += self::writeFile($file, self::PREFIX . $title);
        }

        return $written;
    }

    /** How many pairs are on disk, without touching the database. */
    public static function available(): int
    {
        $count = 0;

        foreach (array_keys(self::FILES) as $file) {
            $count += count(self::pairs($file));
        }

        return $count;
    }

    /** @return int pairs written for this file */
    private static function writeFile(string $file, string $title): int
    {
        $pairs = self::pairs($file);

        if ([] === $pairs) {
            return 0;
        }

        DB::transaction(function () use ($title, $pairs): void {
            $document = ChatbotDocument::updateOrCreate(
                ['title' => $title],
                [
                    // Written, not uploaded: there is no file behind it in the
                    // media library, only these rows.
                    'disk' => 'chat',
                    'path' => '',
                    'original_name' => 'generated',
                    'mime_type' => 'text/plain',
                    'size' => 0,
                    'status' => ChatbotDocument::STATUS_READY,
                    'error' => null,
                    'passage_count' => count($pairs),
                ],
            );

            $document->passages()->delete();

            foreach ($pairs as $ordinal => [$call, $response]) {
                // The answer is what the visitor sees; the question lives only
                // in the searchable text. The responder scores on `searchable`
                // and displays `content`, so putting the question's words in the
                // former keeps the pair findable by what someone types, while
                // the latter reads as a straight answer rather than a FAQ entry
                // that quotes the question back before answering it.
                ChatbotPassage::create([
                    'document_id' => $document->id,
                    'ordinal' => $ordinal,
                    'content' => $response,
                    'searchable' => ChatbotPassage::normalise($call . ' ' . $response),
                ]);
            }
        });

        return count($pairs);
    }

    /**
     * The pairs in a file, as [call, response] tuples.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function pairs(string $file): array
    {
        $path = database_path('chatbot/' . $file);

        if ( ! is_file($path)) {
            throw new RuntimeException("Training file missing: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if ( ! is_array($data) || ! isset($data['training_pairs']) || ! is_array($data['training_pairs'])) {
            throw new RuntimeException("Training file has no training_pairs: {$file}");
        }

        $pairs = [];

        foreach ($data['training_pairs'] as $pair) {
            $call = trim((string) ($pair['call'] ?? ''));
            $response = trim((string) ($pair['response'] ?? ''));

            // A pair with no question cannot be matched, and one with no answer
            // has nothing to say — skip rather than store a dead row.
            if ('' === $call || '' === $response) {
                continue;
            }

            // Drop the parent-ecosystem boilerplate — it is not about the
            // registry and only pollutes results.
            $haystack = mb_strtolower($call . ' ' . $response);

            foreach (self::OFF_TOPIC as $marker) {
                if (str_contains($haystack, $marker)) {
                    continue 2;
                }
            }

            $pairs[] = [$call, $response];
        }

        return array_values($pairs);
    }
}
