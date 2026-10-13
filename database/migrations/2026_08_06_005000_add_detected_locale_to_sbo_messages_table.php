<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which language a message was written in.
 *
 * Decides whether to offer "See translation" at all. Without it the control
 * appeared under every incoming message, including whole threads already in the
 * reader's own language — noise that trains people to ignore the link.
 *
 * Detected once, when the thread is first read, and stored: the answer cannot
 * change, and asking the provider on every render would be a request per bubble
 * per page view.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbo_messages', function (Blueprint $table): void {
            $table->string('detected_locale', 8)->nullable()->after('body');
            // Records the attempt, so an undetectable message is not retried on
            // every view.
            $table->timestamp('detected_at')->nullable()->after('detected_locale');
        });
    }

    public function down(): void
    {
        Schema::table('sbo_messages', function (Blueprint $table): void {
            $table->dropColumn(['detected_locale', 'detected_at']);
        });
    }
};
