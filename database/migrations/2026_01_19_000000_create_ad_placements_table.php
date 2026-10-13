<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ad_placements', function (Blueprint $table): void {
            $table->id();
            $table->string('slot')->index();          // key from config/ads.php
            $table->string('title');
            $table->string('cta_text')->default('Learn More');
            $table->string('cta_url')->nullable();
            $table->unsignedInteger('priority')->default(0); // higher = preferred
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            // Cached aggregate counters (source of truth is ad_events)
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['slot', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_placements');
    }
};
