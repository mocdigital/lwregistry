<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scopes the SBO fee schedule by country. A row with country_id = NULL is the
 * GLOBAL DEFAULT; a row with a specific country_id OVERRIDES it for that
 * country. The calculator resolves country-specific first, then falls back to
 * global. Existing rows keep country_id = NULL, so today's schedule becomes the
 * global default with no behaviour change.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbo_fee_bands', function (Blueprint $table): void {
            $table->foreignId('country_id')->nullable()->after('id')
                ->constrained('countries')->nullOnDelete();
        });

        Schema::table('sbo_capital_types', function (Blueprint $table): void {
            // Drop the old (structure, key) unique before re-scoping it by country.
            $table->dropUnique(['structure', 'key']);
        });

        Schema::table('sbo_capital_types', function (Blueprint $table): void {
            $table->foreignId('country_id')->nullable()->after('id')
                ->constrained('countries')->nullOnDelete();
            $table->unique(['country_id', 'structure', 'key']);
        });

        Schema::table('sbo_fee_settings', function (Blueprint $table): void {
            // Drop the old single-column unique before re-scoping it by country.
            $table->dropUnique(['key']);
        });

        Schema::table('sbo_fee_settings', function (Blueprint $table): void {
            $table->foreignId('country_id')->nullable()->after('id')
                ->constrained('countries')->nullOnDelete();
            $table->unique(['country_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('sbo_fee_settings', function (Blueprint $table): void {
            $table->dropUnique(['country_id', 'key']);
            $table->dropConstrainedForeignId('country_id');
            $table->unique('key');
        });

        Schema::table('sbo_capital_types', function (Blueprint $table): void {
            $table->dropUnique(['country_id', 'structure', 'key']);
            $table->dropConstrainedForeignId('country_id');
            $table->unique(['structure', 'key']);
        });

        Schema::table('sbo_fee_bands', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('country_id');
        });
    }
};
