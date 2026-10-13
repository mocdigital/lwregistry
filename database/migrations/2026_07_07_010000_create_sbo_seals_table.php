<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_seals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sbo_id')->unique()->constrained('sbos')->cascadeOnDelete();
            $table->string('issued_number')->unique();
            $table->string('issued_year', 4);
            $table->string('serial_number', 20);
            $table->string('image_path');
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_seals');
    }
};
