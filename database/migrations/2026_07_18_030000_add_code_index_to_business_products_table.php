<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the product code so admins can look up inventory orders by code
 * quickly (the orders search resolves matching product ids by exact code).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('business_products', function (Blueprint $table): void {
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::table('business_products', function (Blueprint $table): void {
            $table->dropIndex(['code']);
        });
    }
};
