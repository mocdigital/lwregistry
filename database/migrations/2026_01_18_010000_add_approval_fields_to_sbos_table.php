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
            $table->string('application_id')->nullable()->unique()->after('id');

            $table->foreignId('received_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('date_received')->nullable()->after('received_by');

            // pending | approved | declined
            $table->string('verification_status')->default('pending')->after('date_received');

            $table->foreignId('approved_by')->nullable()->after('verification_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('date_approved')->nullable()->after('approved_by');

            $table->string('moc_registration_number')->nullable()->after('date_approved');
            $table->text('decline_reason')->nullable()->after('moc_registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'application_id',
                'date_received',
                'verification_status',
                'date_approved',
                'moc_registration_number',
                'decline_reason',
            ]);
        });
    }
};
