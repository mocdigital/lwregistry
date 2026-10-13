<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            // Share structure gate: private | public | no_shares | sole_partnership
            $table->string('share_structure')->nullable()->after('payment_status');
            // Share-capital inputs (null for non-share structures).
            $table->string('share_capital_type')->nullable()->after('share_structure');
            $table->unsignedBigInteger('issued_shares_volume')->nullable()->after('share_capital_type');
            $table->decimal('per_share_value', 15, 2)->nullable()->after('issued_shares_volume');
            // Resolved fee (in Espees) and the band it fell into, captured at selection time.
            $table->unsignedInteger('calculated_fee')->nullable()->after('per_share_value');
            $table->string('fee_band')->nullable()->after('calculated_fee');
            // Owner-supplied reference for the Espees transfer + when proof was submitted.
            $table->string('payment_reference')->nullable()->after('fee_band');
            $table->timestamp('payment_submitted_at')->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropColumn([
                'share_structure',
                'share_capital_type',
                'issued_shares_volume',
                'per_share_value',
                'calculated_fee',
                'fee_band',
                'payment_reference',
                'payment_submitted_at',
            ]);
        });
    }
};
