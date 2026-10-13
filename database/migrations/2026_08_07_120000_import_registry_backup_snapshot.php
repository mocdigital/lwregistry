<?php

declare(strict_types=1);

use App\Support\SqliteDumpImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class() extends Migration
{
    /**
     * This replays thousands of INSERT statements from a checked-in snapshot.
     * Keep it outside a migration transaction so large live imports do not hold
     * one long-running transaction open.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! filter_var(config('registry.import_snapshot', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        (new SqliteDumpImporter(DB::connection()))
            ->import(database_path('backups/registry-2026-08-07.sql'));

        /*
         * The checked-in snapshot intentionally scrubbed login secrets. Those
         * placeholders are not bcrypt hashes, so Laravel's verifier throws
         * before it can return "invalid credentials". Replace them with a real
         * random hash: the account remains inaccessible until deliberately
         * reset, but auth no longer crashes.
         */
        DB::table('users')
            ->where('password', 'SCRUBBED-NO-LOGIN')
            ->update([
                'password' => Hash::make(Str::random(40)),
                'password_changed_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible: once live data exists, guessing which rows
        // came from the snapshot would be destructive and unreliable.
    }
};
