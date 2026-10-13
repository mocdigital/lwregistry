<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Member-created group chats carry a name the creator chooses. Category rooms
 * store theirs in `industry`; a user group has no industry, so it needs its own
 * column.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbo_conversations', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('industry');
        });
    }

    public function down(): void
    {
        Schema::table('sbo_conversations', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }
};
