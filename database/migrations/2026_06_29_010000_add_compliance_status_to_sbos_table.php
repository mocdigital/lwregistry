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
            // Documents compliance: pending | submitted | compliant
            $table->string('documents_status')->default('pending')->after('code_of_conduct_agreed_at');
            // Registration fee: unpaid | paid
            $table->string('payment_status')->default('unpaid')->after('documents_status');
            // Audit timestamps for when each step was confirmed by an admin.
            $table->timestamp('documents_compliant_at')->nullable()->after('payment_status');
            $table->timestamp('payment_confirmed_at')->nullable()->after('documents_compliant_at');
            // Set once, when the "fully verified" notification has been sent — prevents duplicates.
            $table->timestamp('verified_notified_at')->nullable()->after('payment_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropColumn([
                'documents_status',
                'payment_status',
                'documents_compliant_at',
                'payment_confirmed_at',
                'verified_notified_at',
            ]);
        });
    }
};
