<?php

declare(strict_types=1);

namespace App\Support\Chatbot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The embedder backed by the on-box embedding service (see the fastembed
 * service; localhost only). Anything going wrong — off, timing out, a bad
 * response — returns empty so the caller falls back to the lexical match.
 */
final class HttpEmbedder implements Embedder
{
    public function embed(array $texts): array
    {
        $texts = array_values($texts);

        if ([] === $texts || ! $this->configured()) {
            return [];
        }

        try {
            $response = Http::timeout(30)->acceptJson()->post($this->url('/embed'), ['texts' => $texts]);

            if (! $response->successful()) {
                return [];
            }

            $vectors = $response->json('vectors');

            return is_array($vectors) ? $vectors : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function embedOne(string $text): ?array
    {
        $vectors = $this->embed([$text]);

        if (! isset($vectors[0]) || ! is_array($vectors[0])) {
            return null;
        }

        return array_map('floatval', $vectors[0]);
    }

    public function available(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        // Health is cached briefly so a run of questions does not each ping it,
        // and a service that just went down is noticed within half a minute.
        return (bool) Cache::remember('chatbot_embedder_up', now()->addSeconds(30), function (): bool {
            try {
                return Http::timeout(3)->get($this->url('/health'))->successful();
            } catch (Throwable) {
                return false;
            }
        });
    }

    private function configured(): bool
    {
        return (bool) config('services.embeddings.enabled')
            && '' !== (string) config('services.embeddings.url');
    }

    private function url(string $path): string
    {
        return mb_rtrim((string) config('services.embeddings.url'), '/') . $path;
    }
}
