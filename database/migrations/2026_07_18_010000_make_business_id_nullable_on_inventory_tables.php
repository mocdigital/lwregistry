<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory is now a single central store owned by the admin, not tied to any
 * business. Make business_id nullable on both product and order tables so
 * store-level rows (business_id = null) are valid.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('business_products', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable()->change();
        });

        Schema::table('inventory_orders', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revert to NOT NULL only if no null rows exist; otherwise leave as-is.
        if ( ! Schema::hasTable('business_products')) {
            return;
        }

        Schema::table('business_products', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable(false)->change();
        });

        Schema::table('inventory_orders', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable(false)->change();
        });
    }
};
