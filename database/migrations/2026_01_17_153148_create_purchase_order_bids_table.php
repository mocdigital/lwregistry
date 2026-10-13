<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_bids', function (Blueprint $table): void {
            $table->id();

            // RFQ
            $table->foreignId('purchase_order_id')
                ->constrained()
                ->cascadeOnDelete();

            // User submitting the bid
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Business used to submit the bid
            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();

            // Bid details
            $table->decimal('amount', 12, 2);
            $table->text('proposal')->nullable();

            // Optional attachment (PDF, DOC, etc.)
            $table->string('attachment_path')->nullable();

            $table->enum('status', [
                'pending',
                'accepted',
                'rejected',
            ])->default('pending');

            // Explicit submission time
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            /**
             * Constraints
             */

            // One bid per business per RFQ
            $table->unique(['purchase_order_id', 'business_id']);

            // Performance indexes
            $table->index('purchase_order_id');
            $table->index('user_id');
            $table->index('business_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_bids');
    }
};
