<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings the settings screen collects but had nowhere to keep.
 *
 * The Appearance and Notifications panels were entirely client-side `ref`s —
 * every theme, accent, density and notification toggle reset on reload, so the
 * screen recorded a preference and then forgot it. One JSON column rather than
 * a column per switch, because these are display and delivery choices whose
 * shape changes with the design; see App\Support\UserPreferences.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('preferences')->nullable()->after('admin_page_permissions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};
