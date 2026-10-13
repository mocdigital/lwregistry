<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The GBPC conference form was expanded: a single Full Name replaces first/last,
 * Country/City replaces Country, and Zone, Occupation, "Do you own a business?",
 * the business name and the conference expectations are collected too.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('gbpc_conference_registrations', function (Blueprint $table): void {
            $table->string('full_name')->nullable()->after('title');
            $table->string('country_city')->nullable()->after('email');
            $table->string('zone')->nullable()->after('country_city');
            $table->string('occupation')->nullable()->after('zone');
            $table->string('owns_business')->nullable()->after('occupation');
            $table->string('business_name')->nullable()->after('owns_business');
            $table->text('expectations')->nullable()->after('business_name');
        });

        // Carry the handful of existing rows over to the new shape.
        foreach (DB::table('gbpc_conference_registrations')->get() as $row) {
            DB::table('gbpc_conference_registrations')
                ->where('id', $row->id)
                ->update([
                    'full_name' => mb_trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                    'country_city' => $row->country ?? null,
                ]);
        }

        Schema::table('gbpc_conference_registrations', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'country']);
        });
    }

    public function down(): void
    {
        Schema::table('gbpc_conference_registrations', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('title');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('country')->nullable()->after('email');
        });

        foreach (DB::table('gbpc_conference_registrations')->get() as $row) {
            $parts = preg_split('/\s+/', mb_trim((string) $row->full_name), 2) ?: [];
            DB::table('gbpc_conference_registrations')
                ->where('id', $row->id)
                ->update([
                    'first_name' => $parts[0] ?? '',
                    'last_name' => $parts[1] ?? '',
                    'country' => $row->country_city ?? null,
                ]);
        }

        Schema::table('gbpc_conference_registrations', function (Blueprint $table): void {
            $table->dropColumn([
                'full_name', 'country_city', 'zone', 'occupation',
                'owns_business', 'business_name', 'expectations',
            ]);
        });
    }
};
