<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single product line on an inventory order. Unit price and product name are
 * snapshotted at creation time so historical orders stay accurate even if the
 * product is later re-priced or renamed.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_order_id')->constrained()->cascadeOnDelete();
            // Keep the line if the product is deleted; name snapshot preserves history.
            $table->foreignId('business_product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('line_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_order_items');
    }
};
