<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bid can carry several documents, not just one.
 *
 * The single `attachment_path` stays (the first file still fills it, so nothing
 * that reads it breaks); `attachment_paths` holds the full list as
 * [{path, name}], which is what the bid form now uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->json('attachment_paths')->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->dropColumn('attachment_paths');
        });
    }
};
