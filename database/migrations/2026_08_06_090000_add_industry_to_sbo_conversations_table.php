<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The industry a group conversation belongs to.
 *
 * Every verified business joins the group chat for the industry it registered
 * under, so there is exactly one conversation per industry and the column is
 * unique. Nullable because direct and bid threads have no industry — those are
 * the other two kinds of row this table holds.
 *
 * Membership itself is not stored here: it goes in the existing
 * `sbo_conversation_participants` pivot, which is what `visibleTo()` and the
 * per-member `last_read_at` already read.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbo_conversations', function (Blueprint $table): void {
            $table->string('industry')->nullable()->after('type');
            $table->unique('industry');
        });
    }

    public function down(): void
    {
        Schema::table('sbo_conversations', function (Blueprint $table): void {
            $table->dropUnique(['industry']);
            $table->dropColumn('industry');
        });
    }
};
