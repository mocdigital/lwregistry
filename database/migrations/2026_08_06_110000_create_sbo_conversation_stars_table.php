<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Starred conversations.
 *
 * The chat header draws a star, and it was a disabled button — one of three
 * icons that did nothing.
 *
 * Per user rather than per SBO: a star is a personal bookmark, and two people
 * managing the same listing should not be starring threads for each other. It
 * is also the only shape that works for every kind of conversation — direct
 * threads hang off a connection and have no row in the participants pivot, so
 * there was nowhere on that pivot to record this.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_conversation_stars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('sbo_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_conversation_stars');
    }
};
