<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the assistant has been talking to.
 *
 * The assistant answers questions from the knowledge bank; this is the other
 * half of running one. A visitor who asks about fees and leaves is a lost
 * enquiry — with a name and an address, somebody can follow it up.
 *
 * Keyed on the session, one row per visitor, so a second question does not
 * make a second lead. `session_id` rather than the user: almost everyone this
 * is for is signed out — that is who is deciding whether to register.
 *
 * The email is deliberately nullable. Asking is worth it, and refusing to
 * answer anything until somebody hands one over turns a helpful assistant into
 * a form, so a visitor may skip it and still be helped.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('email')->nullable();

            // What they came in asking about, if they had said before the
            // assistant asked who they were.
            $table->text('first_question')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_leads');
    }
};
