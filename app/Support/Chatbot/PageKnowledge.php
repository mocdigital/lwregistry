<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use App\Models\ChatbotDocument;
use App\Models\ChatbotPassage;
use Illuminate\Support\Facades\DB;

/**
 * What the site itself says, read off its own pages.
 *
 * The public pages are a single-page app, so there is no server-rendered HTML
 * to crawl — the text a visitor reads lives in the interface copy, keyed by the
 * page it belongs to (`about.*`, `advertise.*`, `support.*`, and so on). This
 * gathers that copy, page by page, and writes it into the knowledge bank, so
 * the assistant answers from what the pages actually say rather than only from
 * what an administrator uploaded.
 *
 * Nothing is fetched over the network and nothing leaves the server: it reads
 * the same translation file the pages render from. Re-run it after the copy
 * changes and the assistant is current again.
 *
 * Only the pages a visitor deciding whether to join would read — the marketing
 * and informational ones — not the deep account and settings screens, whose
 * button labels are noise to a prospective member.
 */
final class PageKnowledge
{
    private const PREFIX = 'Page: ';

    /** Interface copy shorter than this is a button or a label, not content. */
    private const MIN_LENGTH = 22;

    /**
     * The pages worth reading, and how each is titled in the bank.
     *
     * @var array<string, string>
     */
    private const PAGES = [
        'home' => 'Home',
        'about' => 'About the registry',
        'advertise' => 'Advertising',
        'support' => 'Support',
        'feedback' => 'Feedback',
        'directory' => 'Business directory',
        'browse' => 'Browsing categories',
        'business_profile' => 'A business profile',
        'procurement_detail' => 'Procurement requests and bidding',
        'procurement_create' => 'Posting a procurement request',
        'bid_wizard' => 'Placing a bid',
        'payment_methods' => 'Paying',
        'payment_required' => 'Registration payment',
        'register_wizard' => 'Registering',
        'sbo_network' => 'The SBO network and messaging',
        'chat' => 'Messaging',
        'profile_dashboard' => 'Your profile dashboard',
        'sbo_progress' => 'Application progress',
        'notifications' => 'Notifications',
        'my_account' => 'Your account',
    ];

    /** @return int the number of pages written */
    public static function refresh(): int
    {
        $strings = self::strings();
        $written = 0;

        foreach (self::PAGES as $namespace => $title) {
            $body = self::copyFor($namespace, $strings);

            if ('' === $body) {
                continue;
            }

            self::write(self::PREFIX . $title, $body);
            $written++;
        }

        return $written;
    }

    /**
     * The English interface copy, flat "namespace.key" => value.
     *
     * @return array<string, string>
     */
    private static function strings(): array
    {
        $path = lang_path('en.json');

        if ( ! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * One page's readable copy, as a block of text.
     *
     * The very short strings are dropped — a page's "Next", "Submit" and "Close"
     * say nothing about the page — and pluralisation forms are flattened to
     * their first variant so the bank does not store the counting syntax.
     *
     * @param  array<string, string>  $strings
     */
    private static function copyFor(string $namespace, array $strings): string
    {
        $lines = [];
        $prefix = $namespace . '.';

        foreach ($strings as $key => $value) {
            if ( ! str_starts_with($key, $prefix) || ! is_string($value)) {
                continue;
            }

            // "{0} none|{1} one|[2,*] many" -> the plain first form.
            $value = (string) preg_replace('/^(?:\{\d+\}|\[\d+,(?:\d+|\*)\])\s?/', '', explode('|', $value)[0]);
            $value = mb_trim($value);

            if (mb_strlen($value) < self::MIN_LENGTH) {
                continue;
            }

            $lines[$value] = $value;
        }

        return implode("\n", array_values($lines));
    }

    /** Written as a document with its passages, replacing any earlier version. */
    private static function write(string $title, string $body): void
    {
        $passages = (new PassageSplitter())->split($body);

        DB::transaction(function () use ($title, $passages): void {
            $document = ChatbotDocument::updateOrCreate(
                ['title' => $title],
                [
                    'disk' => 'chat',
                    'path' => '',
                    'original_name' => 'generated',
                    'mime_type' => 'text/plain',
                    'size' => 0,
                    'status' => ChatbotDocument::STATUS_READY,
                    'error' => null,
                    'passage_count' => count($passages),
                ],
            );

            $document->passages()->delete();

            foreach ($passages as $ordinal => $content) {
                ChatbotPassage::create([
                    'document_id' => $document->id,
                    'ordinal' => $ordinal,
                    'content' => $content,
                    'searchable' => ChatbotPassage::normalise($content),
                ]);
            }
        });
    }
}
