<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_payment_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('sbo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_payment_proofs');
    }
};
