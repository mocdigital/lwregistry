<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sales order raised against a business's inventory. Holds customer details
 * and a running total; line items live in inventory_order_items. Marking an
 * order "paid" is what decrements stock (see InventoryStockManager).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reference')->unique();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();

            // 'retail' or 'bulk' — decides which unit price line items snapshot.
            $table->string('price_type')->default('retail');

            // pending | paid | cancelled
            $table->string('status')->default('pending');

            // Snapshot totals (whole-unit integers) computed from line items.
            $table->unsignedInteger('total_amount')->default(0);

            $table->text('notes')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_orders');
    }
};
