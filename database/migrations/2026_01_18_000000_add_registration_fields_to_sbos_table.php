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
            // Business Information (Section A)
            $table->string('legal_structure')->nullable()->after('business_type');
            $table->string('address')->nullable()->after('region');
            $table->string('city')->nullable()->after('address');
            $table->string('state')->nullable()->after('city');
            $table->string('country')->nullable()->after('state');
            $table->string('premise_type')->nullable()->after('country'); // physical | virtual | both

            // Owner Information (Section B)
            $table->string('owner_surname')->nullable()->after('primary_contact_email');
            $table->string('owner_first_name')->nullable()->after('owner_surname');
            $table->string('owner_country')->nullable()->after('owner_first_name');
            $table->string('owner_residential_address')->nullable()->after('owner_country');
            $table->string('church_zone')->nullable()->after('owner_residential_address');
            $table->string('local_assembly')->nullable()->after('church_zone');
            $table->unsignedInteger('years_as_member')->nullable()->after('local_assembly');
            $table->string('zonal_pastor')->nullable()->after('years_as_member');
            $table->string('local_pastor')->nullable()->after('zonal_pastor');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropColumn([
                'legal_structure',
                'address',
                'city',
                'state',
                'country',
                'premise_type',
                'owner_surname',
                'owner_first_name',
                'owner_country',
                'owner_residential_address',
                'church_zone',
                'local_assembly',
                'years_as_member',
                'zonal_pastor',
                'local_pastor',
            ]);
        });
    }
};
