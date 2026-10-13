<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->string('provider')->default('espees');
            $table->string('local_reference')->unique();
            $table->string('provider_reference')->nullable()->index();
            $table->unsignedInteger('amount');
            $table->string('currency', 50);
            $table->string('gateway_status')->default('initiated')->index();
            $table->string('review_status')->default('none')->index();
            $table->json('raw_payload')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['sbo_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_payment_transactions');
    }
};
