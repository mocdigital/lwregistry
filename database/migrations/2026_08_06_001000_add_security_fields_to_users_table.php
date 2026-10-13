<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Real backing for the Security Overview panel and for two-factor auth.
 *
 * The panel showed three fixed strings for every account — "Two-Factor Auth:
 * ACTIVE", "Password Age: 42 days (Good)", "Last Login Device: Chrome,
 * Nairobi". The first was the worst of the three: it told people their account
 * was protected by a second factor that did not exist anywhere in the codebase.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * Both are encrypted at rest by the model's casts. The secret is
             * enough to mint valid codes, so it is treated like a credential,
             * not a profile field.
             */
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            /*
             * Set only once the user has proved they can read codes from their
             * authenticator. A secret without this is a setup that was started
             * and abandoned, and must NOT gate login — otherwise a mis-scanned
             * QR locks someone out of their own account.
             */
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            // Password age. Backfilled below so existing accounts do not all
            // read "0 days" as though everyone rotated at once.
            $table->timestamp('password_changed_at')->nullable()->after('two_factor_confirmed_at');

            // Last login device, recorded on each successful authentication.
            $table->timestamp('last_login_at')->nullable()->after('password_changed_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->string('last_login_user_agent')->nullable()->after('last_login_ip');
        });

        /*
         * The best available proxy for accounts that predate the column: the
         * password is at least as old as the account. Better than null, which
         * the panel would have to render as "unknown" for everyone.
         */
        DB::table('users')->whereNull('password_changed_at')->update([
            'password_changed_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'password_changed_at',
                'last_login_at',
                'last_login_ip',
                'last_login_user_agent',
            ]);
        });
    }
};
