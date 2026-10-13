<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing business_products table into a stock-aware inventory
 * item: SKU, retail + bulk pricing (stored as whole-unit integers, matching
 * the app's ad_campaigns/sbo money convention) and an on-hand quantity.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('business_products', function (Blueprint $table): void {
            $table->string('sku')->nullable()->after('name');
            $table->unsignedInteger('retail_price')->default(0)->after('description');
            $table->unsignedInteger('bulk_price')->default(0)->after('retail_price');
            $table->unsignedInteger('bulk_min_quantity')->default(1)->after('bulk_price');
            $table->integer('stock_quantity')->default(0)->after('bulk_min_quantity');
            $table->unsignedInteger('low_stock_threshold')->default(0)->after('stock_quantity');
            $table->boolean('is_active')->default(true)->after('low_stock_threshold');

            // SKU is unique per business (not globally).
            $table->unique(['business_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::table('business_products', function (Blueprint $table): void {
            $table->dropUnique(['business_id', 'sku']);
            $table->dropColumn([
                'sku',
                'retail_price',
                'bulk_price',
                'bulk_min_quantity',
                'stock_quantity',
                'low_stock_threshold',
                'is_active',
            ]);
        });
    }
};
