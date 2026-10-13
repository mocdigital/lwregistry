<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // Key/value store for the tunable, non-banded fee settings:
        // flat_fee, currency, merchant_name, merchant_app.
        Schema::create('sbo_fee_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_fee_settings');
    }
};
