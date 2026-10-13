<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ad_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_placement_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);            // impression | click
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['ad_placement_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_events');
    }
};
