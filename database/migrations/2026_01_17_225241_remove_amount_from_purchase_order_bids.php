<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->dropColumn('amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->decimal('amount', 12, 2)->nullable();
        });
    }
};
