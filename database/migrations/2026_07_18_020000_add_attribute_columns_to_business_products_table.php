<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds merchandising attributes to inventory products: an internal product
 * code (distinct from SKU), a free-text category, and size/colour variants.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('business_products', function (Blueprint $table): void {
            if ( ! Schema::hasColumn('business_products', 'code')) {
                $table->string('code')->nullable()->after('name');
            }

            if ( ! Schema::hasColumn('business_products', 'category')) {
                $table->string('category')->nullable()->after('code');
            }

            if ( ! Schema::hasColumn('business_products', 'size')) {
                $table->string('size')->nullable()->after('low_stock_threshold');
            }

            if ( ! Schema::hasColumn('business_products', 'colour')) {
                $table->string('colour')->nullable()->after('size');
            }
        });
    }

    public function down(): void
    {
        Schema::table('business_products', function (Blueprint $table): void {
            $table->dropColumn(['code', 'category', 'size', 'colour']);
        });
    }
};
