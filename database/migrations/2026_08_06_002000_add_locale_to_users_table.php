<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The member's chosen interface language.
 *
 * Its own column rather than a key inside `preferences`, for two reasons: the
 * chat translation feature queries it ("which language does this reader want
 * this message in?"), and a JSON key is awkward to index or filter on.
 *
 * Nullable means "never chose". That is different from choosing English —
 * a null falls back to the browser's Accept-Language on first visit, while an
 * explicit 'en' means the member picked English and should not be second-
 * guessed by their browser settings.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 8)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
