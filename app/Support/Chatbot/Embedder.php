<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

/**
 * Turns text into a vector of its meaning.
 *
 * One implementation talks to the self-hosted embedding service; a test binds a
 * fake. Either way the model runs on this server — the same rule the translator
 * follows — so a question and the knowledge never leave it.
 *
 * Every method degrades to nothing rather than throwing: an embedder that is
 * off, unreachable or misconfigured returns empty, and the assistant falls back
 * to its lexical match. The meaning-based search is an upgrade, not a crutch.
 */
interface Embedder
{
    /**
     * Embed a batch, in order.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>  One vector per input, or [] on failure.
     */
    public function embed(array $texts): array;

    /**
     * @return array<int, float>|null  The vector, or null if it could not be made.
     */
    public function embedOne(string $text): ?array;

    /** Whether the service is configured and answering right now. */
    public function available(): bool;
}
