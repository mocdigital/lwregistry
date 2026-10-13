<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ('mysql' === DB::connection()->getDriverName()) {
            DB::statement('ALTER TABLE businesses ADD FULLTEXT INDEX fulltext_search (name, description, address)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ('mysql' === DB::connection()->getDriverName()) {
            DB::statement('ALTER TABLE businesses DROP INDEX fulltext_search');
        }
    }
};
