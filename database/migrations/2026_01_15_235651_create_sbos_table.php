<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbos', function (Blueprint $table): void {
            $table->id();

            // Identity
            $table->string('name');
            $table->string('slug')->unique();

            // Registration & compliance
            $table->string('rc_number')->nullable();
            $table->string('tax_number')->nullable();

            // Classification
            $table->string('business_type')->nullable();
            $table->string('category')->nullable();
            $table->string('region')->nullable();

            // Business profile
            $table->date('established_at')->nullable();
            $table->string('website')->nullable();
            $table->unsignedInteger('staff_count')->nullable();
            $table->string('business_model')->nullable();

            // Primary contact
            $table->string('primary_contact_name')->nullable();
            $table->string('primary_contact_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('kingschat_handle')->nullable();

            // Directors (JSON)
            $table->json('directors')->nullable();
            /*
              Example structure:
              [
                {
                  "name": "John Doe",
                  "email": "john@example.com",
                  "phone": "+2348012345678"
                },
                {
                  "name": "Jane Smith",
                  "email": null,
                  "phone": null
                }
              ]
            */

            // Description
            $table->text('description')->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            // Audit
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbos');
    }
};
