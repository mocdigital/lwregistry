<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Make editor output safe to render.
 *
 * The procurement description and terms are written by one business and read
 * by every other, so the markup they produce is untrusted the moment it leaves
 * the browser — a client-side editor restricts what a person can TYPE, not
 * what can be posted to the endpoint.
 *
 * An allowlist, not a blocklist. Every "strip the dangerous bits" sanitiser is
 * a list of the attacks its author thought of; this one keeps a short set of
 * tags and attributes and discards everything else, so a tag nobody has heard
 * of is removed by default rather than by omission.
 */
final class RichText
{
    /**
     * Tags a document may contain.
     *
     * Formatting and structure only, matched to what the editor can actually
     * produce. No images (they would be remote URLs loaded from our pages) and
     * no headings above h3 — the page already owns h1 and h2. Allowing a tag
     * nothing can produce is surface area for no benefit.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'h3', 'h4', 'a', 'sub', 'sup',
    ];

    /**
     * Attributes each tag may keep. Everything else goes — including every
     * `on*` handler and every `style`, which is how most markup-based attacks
     * arrive.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],

        /*
         * Quill writes alignment and indentation as CLASSES — ql-align-center,
         * ql-indent-2 — rather than inline styles. `class` is therefore
         * allowed on the blocks that carry them and then filtered to the
         * known names; see cleanClass(). A free `class` attribute would let a
         * writer borrow any style in the application's own stylesheet.
         */
        'p' => ['class', 'style'],
        'h3' => ['class', 'style'],
        'h4' => ['class', 'style'],
        'blockquote' => ['class'],
        'ol' => ['class'],
        'ul' => ['class'],

        /*
         * Quill 2 marks a bulleted list as <ol><li data-list="bullet">, so the
         * attribute is what distinguishes a bulleted list from a numbered one.
         * Drop it and every list renders numbered.
         */
        'li' => ['class', 'data-list'],
    ];

    /**
     * Class names a block may keep.
     *
     * Prefix-matched against the editor's own two families. Anything else is
     * removed — a free `class` attribute would let whoever writes a
     * description borrow any style in the application's stylesheet, including
     * the ones that position things.
     *
     * @var array<int, string>
     */
    private const ALLOWED_CLASS_PREFIXES = ['ql-align-', 'ql-indent-'];

    /**
     * The only style declarations that survive.
     *
     * `style` is the attribute most markup attacks arrive through, so it is
     * not allowed as a whole — it is parsed, and one property with four known
     * values is kept. Everything else, including anything that could position
     * an element over the page, is discarded.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_STYLES = [
        'text-align' => ['left', 'center', 'right', 'justify'],
    ];

    /** Schemes a link may use. `javascript:` is the reason this list exists. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    public static function clean(?string $html): string
    {
        $html = mb_trim((string) $html);

        if ('' === $html) {
            return '';
        }

        $document = new DOMDocument();

        /*
         * Parsed as a fragment inside a container, with entities left alone.
         * Without the meta charset, DOMDocument assumes ISO-8859-1 and mangles
         * every non-ASCII character in the document.
         */
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="richtext-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('richtext-root');

        if ( ! $root instanceof DOMElement) {
            return '';
        }

        self::cleanNode($root);

        $out = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }

        return mb_trim($out);
    }

    /** Whether anything survives once the markup is removed. */
    public static function isEmpty(?string $html): bool
    {
        $text = html_entity_decode(strip_tags(self::clean($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '' === mb_trim(str_replace("\u{a0}", ' ', $text));
    }

    /** Rich text as a plain sentence, for a preview or a search index. */
    public static function toPlainText(?string $html): string
    {
        $text = strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * Walk depth-first, removing what is not allowed.
     *
     * A disallowed element is UNWRAPPED rather than deleted — its children move
     * up in its place — so a stray <div> or <span> loses the tag and keeps the
     * words. Deleting the element would silently discard the sentence inside
     * it, and a description that quietly loses a paragraph is worse than one
     * that loses its formatting.
     */
    private static function cleanNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ( ! $child instanceof DOMElement) {
                // Text and everything else that is not an element stays, except
                // comments, which can carry payloads in some parsers.
                if (XML_COMMENT_NODE === $child->nodeType) {
                    $child->parentNode?->removeChild($child);
                }

                continue;
            }

            $tag = mb_strtolower($child->nodeName);

            self::cleanNode($child);

            if ( ! in_array($tag, self::ALLOWED_TAGS, true)) {
                /*
                 * <script> and <style> are the exception: their CONTENT is the
                 * payload, so unwrapping one would paste the script's source
                 * into the page as text.
                 */
                if (in_array($tag, ['script', 'style'], true)) {
                    $child->parentNode?->removeChild($child);

                    continue;
                }

                self::unwrap($child);

                continue;
            }

            self::cleanAttributes($child, $tag);
        }
    }

    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if ( ! in_array(mb_strtolower($attribute->nodeName), $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        if ($element->hasAttribute('class')) {
            $class = self::cleanClass($element->getAttribute('class'));

            if ('' === $class) {
                $element->removeAttribute('class');
            } else {
                $element->setAttribute('class', $class);
            }
        }

        if ($element->hasAttribute('style')) {
            $style = self::cleanStyle($element->getAttribute('style'));

            if ('' === $style) {
                $element->removeAttribute('style');
            } else {
                $element->setAttribute('style', $style);
            }
        }

        if ('a' !== $tag) {
            return;
        }

        $href = mb_trim($element->getAttribute('href'));

        if ('' === $href || ! self::schemeIsAllowed($href)) {
            $element->removeAttribute('href');

            return;
        }

        // Anything leaving the site opens away from it and cannot reach back
        // through window.opener.
        $element->setAttribute('target', '_blank');
        $element->setAttribute('rel', 'noopener noreferrer nofollow');
    }

    /**
     * Keep the declarations on the allowlist and drop the rest.
     *
     * Parsed rather than pattern-matched: "text-align:center" and
     * "TEXT-ALIGN : CENTER ; position:fixed" are the same declaration to a
     * browser, and a regex looking for the first is trivially walked past.
     */
    private static function cleanStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            if ( ! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = explode(':', $declaration, 2);

            $property = mb_strtolower(mb_trim($property));
            $value = mb_strtolower(mb_trim($value));

            if (in_array($value, self::ALLOWED_STYLES[$property] ?? [], true)) {
                $kept[] = $property . ': ' . $value;
            }
        }

        return implode('; ', $kept);
    }

    /** Keep only the editor's own alignment and indent classes. */
    private static function cleanClass(string $class): string
    {
        $kept = [];

        foreach (preg_split('/\s+/', mb_trim($class)) ?: [] as $name) {
            foreach (self::ALLOWED_CLASS_PREFIXES as $prefix) {
                // The suffix is a value, not a name: ql-indent-3 is fine,
                // ql-indent-</style><script> is not.
                if (str_starts_with($name, $prefix) && preg_match('/^[a-z0-9-]+$/', $name)) {
                    $kept[] = $name;

                    break;
                }
            }
        }

        return implode(' ', array_unique($kept));
    }

    private static function schemeIsAllowed(string $href): bool
    {
        // A relative link has no scheme and stays on this site, which is fine.
        if (str_starts_with($href, '/') || str_starts_with($href, '#')) {
            return true;
        }

        $scheme = mb_strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, self::ALLOWED_SCHEMES, true);
    }

    /** Replace an element with its own children. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (null === $parent) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }
}
