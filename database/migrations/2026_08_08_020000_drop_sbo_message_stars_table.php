<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the per-message star.
 *
 * It was added with the message action menu and taken out of that menu the
 * same day, which left a table, a model and an endpoint that nothing could
 * reach. An unreachable feature is worse than an absent one: it still has to
 * be understood by whoever reads the code next, and it is the kind of thing
 * that gets wired back up by accident.
 *
 * The CONVERSATION star is untouched — that is a different mark, on the chat
 * header, and the inbox's Starred chip filters by it.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sbo_message_stars');
    }

    public function down(): void
    {
        Schema::create('sbo_message_stars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('sbo_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
        });
    }
};
