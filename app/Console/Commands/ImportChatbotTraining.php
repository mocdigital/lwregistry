<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Chatbot\PageKnowledge;
use App\Support\Chatbot\SiteKnowledge;
use App\Support\Chatbot\TrainingLibrary;
use Illuminate\Console\Command;

/**
 * Load the curated Q&A library, and the registry's own facts, into the bank.
 *
 * Run on deploy after the training files change. It rewrites only what these
 * two writers own, so an administrator's uploaded documents are left alone.
 */
final class ImportChatbotTraining extends Command
{
    protected $signature = 'chatbot:import-training';

    protected $description = 'Write the curated Q&A pairs and registry facts into the assistant knowledge bank';

    public function handle(): int
    {
        $this->info('Importing curated Q&A pairs…');
        $pairs = TrainingLibrary::refresh();
        $this->line("  {$pairs} pairs written.");

        $this->info('Refreshing registry facts…');
        $docs = SiteKnowledge::refresh();
        $this->line("  {$docs} documents written.");

        $this->info('Reading the site pages…');
        $pages = PageKnowledge::refresh();
        $this->line("  {$pages} pages written.");

        // The passages above were rewritten, so they carry no vectors yet; embed
        // the ones now missing one. Skips itself if the service is off.
        $this->info('Embedding for semantic search…');
        $this->call('chatbot:embed-knowledge');

        $this->info('Done.');

        return self::SUCCESS;
    }
}
