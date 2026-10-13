<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cached machine translations of chat messages.
 *
 * A cache, not a feature in itself. Providers bill per character, and a message
 * in a busy conversation is read by several people who may share a language —
 * translating it once per language instead of once per reader per click is the
 * difference between a few characters and an unbounded number.
 *
 * Keyed on (message, locale) because that is exactly what identifies a
 * translation: the same message into French is the same string no matter who
 * asked for it.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_message_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')
                ->constrained('sbo_messages')
                ->cascadeOnDelete();
            $table->string('locale', 8);
            $table->text('body');
            /*
             * What the provider detected the original to be. Drives the
             * "Translated from French" line, and lets the chat skip offering
             * translation on messages already in the reader's language.
             */
            $table->string('source_locale', 8)->nullable();
            $table->string('provider', 32);
            $table->timestamps();

            $table->unique(['message_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_message_translations');
    }
};
