<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sbo_id')
                ->constrained('sbos')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('description');

            // Uploaded RFQ / purchase order document
            $table->string('document_path')->nullable();

            // Optional budget
            $table->decimal('budget', 12, 2)->nullable();

            // Order / bid deadline (date only)
            $table->date('order_deadline')->nullable();

            $table->enum('status', [
                'draft',
                'open',
                'awarded',
                'completed',
                'cancelled',
            ])->default('draft');

            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
