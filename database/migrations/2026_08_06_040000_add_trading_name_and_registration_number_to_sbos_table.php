<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trading name and company registration number.
 *
 * Both are drawn on the registration wizard's business step — Registration
 * Number marked required — and neither had anywhere to go, so the step could
 * not be bound to the form without silently discarding them.
 *
 * `registration_number` is the applicant's own company registration (RC-482913
 * and the like). It is not `moc_registration_number`, which is the number this
 * registry issues on approval; conflating the two would overwrite a registry
 * number with whatever the applicant typed.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->string('trading_name')->nullable()->after('name');
            $table->string('registration_number')->nullable()->after('legal_structure');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropColumn(['trading_name', 'registration_number']);
        });
    }
};
