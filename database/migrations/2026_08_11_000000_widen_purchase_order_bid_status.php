<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bid's status is a plain string, not a fixed enum.
 *
 * The column was enum('pending','accepted','rejected'), but the code marks a new
 * bid 'submitted' — which MySQL, in strict mode, rejected with "Data truncated
 * for column 'status'", 500-ing every bid submission. SQLite did not enforce it,
 * so the tests never saw it. A short string holds the vocabulary the code
 * actually uses (submitted, accepted, rejected) without a schema change each
 * time it grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->string('status', 20)->default('submitted')->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending')->change();
        });
    }
};
