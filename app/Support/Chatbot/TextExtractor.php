<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Get the words out of an uploaded document.
 *
 * Four formats, each read locally. Nothing is sent anywhere: the whole point
 * of the knowledge bank is that a business's own sales material stays on its
 * own server.
 */
final class TextExtractor
{
    /**
     * What can be uploaded, and how each is read.
     *
     * A closed list. "Anything with text in it" is not a specification, and a
     * format nobody can read produces a document that looks accepted and
     * answers nothing.
     *
     * @var array<string, string>
     */
    public const ACCEPTED = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'text/plain' => 'text',
        'text/markdown' => 'text',
        'text/csv' => 'text',
    ];

    /** @var array<int, string> */
    public const EXTENSIONS = ['pdf', 'docx', 'txt', 'md', 'csv'];

    public function extract(string $absolutePath, string $mimeType): string
    {
        $kind = self::ACCEPTED[$mimeType] ?? null;

        if (null === $kind) {
            throw new RuntimeException('That file type cannot be read.');
        }

        $text = match ($kind) {
            'pdf' => $this->fromPdf($absolutePath),
            'docx' => $this->fromDocx($absolutePath),
            default => (string) file_get_contents($absolutePath),
        };

        return $this->tidy($text);
    }

    private function fromPdf(string $path): string
    {
        try {
            return (new PdfParser())->parseFile($path)->getText();
        } catch (Throwable $exception) {
            throw new RuntimeException('This PDF could not be read: ' . $exception->getMessage());
        }
    }

    /**
     * A .docx is a zip of XML.
     *
     * `word/document.xml` holds the body. Paragraph and line-break tags become
     * newlines before the rest of the markup is stripped, or every paragraph
     * runs into the next and the passages lose their boundaries.
     */
    private function fromDocx(string $path): string
    {
        $zip = new ZipArchive();

        if (true !== $zip->open($path)) {
            throw new RuntimeException('This document could not be opened.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (false === $xml) {
            throw new RuntimeException('This document has no readable body.');
        }

        $xml = preg_replace('#</w:p>|<w:br\s*/>#', "\n", $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Collapse the whitespace a converter leaves behind.
     *
     * PDF extraction in particular produces runs of spaces and stray line
     * breaks mid-sentence; left alone they end up in the answer a customer
     * reads.
     */
    private function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return mb_trim($text);
    }
}
