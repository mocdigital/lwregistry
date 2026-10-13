<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_capital_types', function (Blueprint $table): void {
            $table->id();
            // Which share structure offers this capital type (e.g. private, public).
            $table->string('structure')->index();
            $table->string('key');   // stored value, e.g. "ordinary"
            $table->string('label'); // display, e.g. "Ordinary Shares"
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['structure', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_capital_types');
    }
};
