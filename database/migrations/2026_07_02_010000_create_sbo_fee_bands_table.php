<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_fee_bands', function (Blueprint $table): void {
            $table->id();
            // Which share structure this band applies to (e.g. private, public).
            $table->string('structure')->index();
            // Matches when equity size <= max_equity; null = open-ended top band.
            $table->unsignedBigInteger('max_equity')->nullable();
            $table->unsignedInteger('fee'); // in the configured currency (Espees)
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_fee_bands');
    }
};
