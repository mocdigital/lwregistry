<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q&A on a procurement request.
 *
 * The RFQ detail screen draws a full Q&A tab — guidelines panel, an "Ask
 * Question" button, answered/unanswered entries — with no table behind any of
 * it; INTEGRATION.md recorded it as "Documents, Q&A, followers | RFQ detail |
 * No tables".
 *
 * The design states the disclosure rule on the screen itself: "Your name and
 * contact information will only be viewable by the agency. It will be
 * displayed anonymously to all other users." So the asker is stored, and who
 * may see them is decided at read time — not by withholding the association.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            // Who asked. Kept even when the account goes, so the thread stays
            // readable — the question is part of the solicitation record.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sbo_id')->nullable()->constrained('sbos')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('body');
            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_questions');
    }
};
