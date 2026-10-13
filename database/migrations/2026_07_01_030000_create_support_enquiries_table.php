<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('support_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sbo_id')->nullable()->constrained('sbos')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('sbo_number')->nullable();
            $table->string('subject');
            $table->text('message');
            $table->string('status')->default('open');
            $table->text('admin_response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('sbo_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_enquiries');
    }
};
