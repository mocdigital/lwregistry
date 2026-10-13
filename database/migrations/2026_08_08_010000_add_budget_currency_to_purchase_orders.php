<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Say which currency a budget is in.
 *
 * `budget` held a bare number and the form printed "NGN" beside it as a fixed
 * label — so a Kenyan buyer entering 500000 was published as ₦500,000, and a
 * bidder reading it had no way to know it was wrong. On a registry that spans
 * several countries and prices its own fees in Espees, a number with no
 * currency is not a budget.
 *
 * Nullable, and existing rows are left alone rather than being assumed to be
 * Naira: the ones already posted were entered against a label that said NGN,
 * but guessing on their behalf writes a fact nobody stated. They display
 * without a currency, exactly as they were entered.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('budget_currency', 8)->nullable()->after('budget');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn('budget_currency');
        });
    }
};
