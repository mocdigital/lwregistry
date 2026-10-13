<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Businesses are one of two entity types: SBO (Strategic Business Organization)
 * or COB (Citizen Owned Business). Everyone registered so far is an SBO, which
 * is the default the column backfills to.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->string('entity_type', 8)->default('SBO')->after('business_type');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropColumn('entity_type');
        });
    }
};
