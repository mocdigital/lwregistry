<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to a procurement request.
 *
 * The detail screen's Documents tab drew two sections — "Project Documents" and
 * "Attachments" — with checkboxes and a "Download Selected Items" button, all
 * of it hardcoded: the same security plan PDF on every request, and a button
 * with nothing behind it. A request could only ever hold one file
 * (`purchase_orders.document_path`), so the sections could not have been real.
 *
 * `document_path` is left alone. Existing requests keep it and it is listed
 * alongside these, so nothing already uploaded disappears.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            // Which of the two lists the design draws this belongs in.
            $table->string('section')->default('project');
            $table->string('name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['purchase_order_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_documents');
    }
};
