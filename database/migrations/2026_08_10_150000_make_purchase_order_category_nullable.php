<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A draft may not have a category yet.
 *
 * Publishing still requires one (the create rules enforce it), but a request
 * being worked on can be saved before that choice is made, so the column has to
 * allow null. Display already treats a missing category safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left nullable: reverting would fail on any draft that has no category.
    }
};
