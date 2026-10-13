<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChatbotPassage;
use App\Support\Chatbot\Embedder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Give every knowledge passage its meaning-vector, for semantic search.
 *
 * Runs after the training import (passages are rewritten there, losing their
 * vectors), and skips quietly when the embedding service is off — the assistant
 * simply keeps using its lexical match until the vectors are back.
 */
final class EmbedChatbotKnowledge extends Command
{
    protected $signature = 'chatbot:embed-knowledge {--all : Re-embed every passage, not only those missing a vector}';

    protected $description = 'Embed the assistant knowledge passages for semantic search';

    public function handle(Embedder $embedder): int
    {
        if (! $embedder->available()) {
            $this->warn('Embedding service is not available; leaving passages on the lexical match.');

            return self::SUCCESS;
        }

        $query = ChatbotPassage::query()
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('embedding'));

        $total = (clone $query)->count();

        if (0 === $total) {
            $this->info('Nothing to embed.');

            return self::SUCCESS;
        }

        $this->info("Embedding {$total} passages…");
        $done = 0;

        $ok = $query
            ->select(['id', 'content'])
            ->chunkById(64, function ($rows) use ($embedder, $total, &$done): bool {
                // The passage text, capped: the model has a token limit and the
                // opening of a passage carries its meaning.
                $texts = $rows->map(fn (ChatbotPassage $p): string => Str::limit((string) $p->content, 1200, ''))->all();

                $vectors = $embedder->embed(array_values($texts));

                if (count($vectors) !== $rows->count()) {
                    $this->error('An embedding batch failed; stopping so nothing is half-written.');

                    return false;
                }

                foreach ($rows->values() as $i => $row) {
                    ChatbotPassage::query()->whereKey($row->id)->update(['embedding' => json_encode($vectors[$i])]);
                }

                $done += $rows->count();
                $this->line("  {$done}/{$total}");

                return true;
            });

        $this->info($ok ? 'Done.' : 'Stopped early.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
