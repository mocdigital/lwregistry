<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\IndustryGroupChat;
use Illuminate\Console\Command;

/**
 * Make sure every category has a network group chat.
 *
 * Group rooms are otherwise created when the first business verifies into a
 * category, so a category no one has joined yet has no room. This creates the
 * missing ones (with the registry in each) so all groups are present. Safe to
 * re-run; run it after adding categories.
 */
final class EnsureCategoryGroups extends Command
{
    protected $signature = 'sbo:ensure-category-groups';

    protected $description = 'Create the network group chat for every category';

    public function handle(): int
    {
        $count = IndustryGroupChat::ensureEveryCategory();

        $this->info("Ensured a group room for {$count} categories.");

        return self::SUCCESS;
    }
}
