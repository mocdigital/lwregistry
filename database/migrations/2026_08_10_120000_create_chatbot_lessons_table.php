<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the assistant learns from the people answering for it.
 *
 * When an administrator takes over a conversation and answers a question the
 * bank could not, that answer is worth keeping: the next visitor to ask the
 * same thing should get it from the assistant rather than wait for a person
 * again. Each admin reply, paired with the question it answered, is captured
 * here as a lesson — reviewed and then written into the bank, or approved
 * automatically when an administrator has turned that on.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_lessons', function (Blueprint $table): void {
            $table->id();

            // The conversation it came from, for context when reviewing.
            $table->string('session_id', 64)->nullable()->index();

            // The pair. The question is what a visitor asked; the answer is what
            // the administrator replied. Editable before approval, because a
            // chat reply is not always phrased as a reusable answer.
            $table->text('question');
            $table->text('answer');

            /*
             * pending  — captured, waiting for an administrator to approve
             * approved — written into the bank; `passage_id` points at it
             * dismissed — not worth keeping
             */
            $table->string('status', 16)->default('pending')->index();

            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('passage_id')->nullable()->constrained('chatbot_passages')->nullOnDelete();

            $table->timestamps();
        });

        // A single flag: whether an admin reply is written into the bank at once
        // or waits for review. A tiny key-value table rather than a column on a
        // table that has nothing else to do with it.
        Schema::create('chatbot_settings', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_settings');
        Schema::dropIfExists('chatbot_lessons');
    }
};
