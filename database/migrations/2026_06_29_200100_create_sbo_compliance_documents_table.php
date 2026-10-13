<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_compliance_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->foreignId('compliance_document_type_id')
                ->constrained('compliance_document_types')
                ->cascadeOnDelete();
            // pending | approved | rejected
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // One current submission row per (SBO, document type).
            $table->unique(['sbo_id', 'compliance_document_type_id'], 'sbo_doc_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_compliance_documents');
    }
};
