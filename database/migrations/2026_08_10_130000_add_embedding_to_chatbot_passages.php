<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A passage's meaning, as a vector.
 *
 * The self-hosted embedding model turns each passage into a list of numbers;
 * the assistant compares a question's vector against these to find the passage
 * that MEANS the same thing, rather than the one that shares the most words.
 * Nullable, so a bank that has not been embedded yet simply falls back to the
 * lexical match — the embedding is an upgrade, never a requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_passages', function (Blueprint $table): void {
            // The vector as a JSON array of floats. `json` keeps it compact and
            // is read back once per query and cached, not filtered on in SQL.
            $table->json('embedding')->nullable()->after('searchable');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_passages', function (Blueprint $table): void {
            $table->dropColumn('embedding');
        });
    }
};
