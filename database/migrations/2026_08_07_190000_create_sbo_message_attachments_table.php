<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files sent in a chat.
 *
 * `sbo_messages` carried a body and nothing else, so the only thing that could
 * be sent between two businesses was a paragraph — no quotation, no drawing,
 * no photograph of the goods. A row per file rather than columns on the
 * message, because one message may carry several.
 *
 * The bytes live on the private `chat` disk and are streamed through a route
 * that checks conversation membership. On a public disk every file in every
 * private conversation would be readable by anyone who guessed the path.
 *
 * `kind` is stored rather than derived at read time: the thread renders a
 * photograph inline, a video in a player and a document as a row, and deciding
 * that from the MIME type on every render means the decision can drift between
 * the upload rules and the display.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('sbo_messages')->cascadeOnDelete();

            // image | video | document. See SboMessageAttachment::KINDS.
            $table->string('kind', 16);

            // The disk is recorded so a later move to S3 does not orphan
            // everything written before it.
            $table->string('disk', 32)->default('chat');
            $table->string('path');

            // What the sender called it. Never used to build the stored path —
            // that is generated — so a hostile filename cannot escape the
            // directory or overwrite a neighbour's file.
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');

            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_message_attachments');
    }
};
