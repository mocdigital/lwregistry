<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support is one of the four pages reachable signed out, and
 * `SboNetworkWriteController@supportEnquiry` already writes
 * `'user_id' => $request->user()?->id` — but the column is NOT NULL, so every
 * enquiry from a signed-out visitor died on an integrity constraint the moment
 * the form was actually wired to it.
 *
 * Nullable, and the foreign key falls to null on delete rather than taking the
 * enquiry with it: a closed account should not erase the support history.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('support_enquiries', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('support_enquiries', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_enquiries', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        // Rows without a user cannot survive the constraint coming back.
        DB::table('support_enquiries')->whereNull('user_id')->delete();

        Schema::table('support_enquiries', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
