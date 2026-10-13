<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sales assistant's knowledge bank.
 *
 * An administrator uploads a document; its text is extracted once and split
 * into passages; a visitor's question is answered from those passages.
 *
 * TWO TABLES, not one. The document is what an administrator manages — upload
 * it, see it, remove it. The passage is what a question is matched against.
 * Keeping the extracted text only on the document would mean scanning whole
 * files per question and answering with the whole file; splitting at upload
 * means the answer is the paragraph that actually addresses the question.
 *
 * The extracted text is stored rather than re-read from the file on every
 * question: parsing a PDF is expensive, the file does not change, and the
 * bytes stay on a private disk that the answering path never has to touch.
 *
 * `sbo_chatbot_conversations` is not for the answers — it is for the
 * QUESTIONS. What people ask and, more usefully, what the bank could not
 * answer is the whole reason to run one of these: it is a list of what
 * customers want to know and the sales material that does not yet exist.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');

            // The private disk holding the original, so it can be re-read or
            // downloaded by an administrator later.
            $table->string('disk', 32)->default('chat');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');

            /*
             * Extraction can fail — an image-only PDF has no text in it at
             * all — and a document that yielded nothing must be visibly
             * broken rather than quietly useless.
             */
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedInteger('passage_count')->default(0);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chatbot_passages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('chatbot_documents')->cascadeOnDelete();

            // Position in the source, so a passage can say where it came from
            // and neighbouring passages can be read in order.
            $table->unsignedInteger('ordinal');

            $table->text('content');

            /*
             * The same text, folded to lowercase with punctuation stripped.
             * Matching against this rather than `content` means the query does
             * not have to normalise on every search, and "Espees," matches
             * "espees".
             */
            $table->text('searchable');

            $table->timestamps();

            $table->index(['document_id', 'ordinal']);
        });

        Schema::create('chatbot_conversations', function (Blueprint $table): void {
            $table->id();

            // A visitor is usually a guest, so the session is what ties their
            // questions together. The user is recorded when there is one.
            $table->string('session_id', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('question');
            $table->text('answer')->nullable();

            /*
             * Whether the bank had anything to say. The unanswered questions
             * are the valuable half: they are a list of what customers want to
             * know and the material that does not yet exist.
             */
            $table->boolean('answered')->default(false);

            // The passages used, for checking an answer against its source.
            $table->json('passage_ids')->nullable();

            $table->timestamps();

            $table->index(['answered', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_conversations');
        Schema::dropIfExists('chatbot_passages');
        Schema::dropIfExists('chatbot_documents');
    }
};
