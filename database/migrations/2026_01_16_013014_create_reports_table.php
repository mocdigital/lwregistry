<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();

            // Relations
            $table->foreignId('sbo_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('admin_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Report meta
            $table->string('title');
            $table->string('period');              // e.g. Q1 2026, March 2026
            $table->date('report_date');

            // Performance metrics (JSON)
            $table->json('metrics');

            // Workflow state
            $table->enum('status', ['draft', 'submitted', 'approved'])
                ->default('submitted');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
