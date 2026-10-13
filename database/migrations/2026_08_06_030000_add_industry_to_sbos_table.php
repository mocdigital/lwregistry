<?php

declare(strict_types=1);

use App\Support\SboIndustries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The industry an SBO trades in.
 *
 * The directory's "All Industries" filter shipped disabled because there was
 * nothing behind it — `sbos` carried `category` alone. See App\Support\
 * SboIndustries for why this is a column rather than a lookup at read time.
 *
 * Existing rows are classified from the category they already hold, so the
 * filter is useful the moment it is switched on rather than only for listings
 * registered afterwards.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->string('industry')->nullable()->after('category');
            $table->index('industry');
        });

        // One UPDATE per industry rather than per row: there are a dozen
        // industries and potentially a great many SBOs.
        foreach (SboIndustries::all() as $industry) {
            DB::table('sbos')
                ->whereIn('category', SboIndustries::categoriesIn($industry)->all())
                ->update(['industry' => $industry]);
        }
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropIndex(['industry']);
            $table->dropColumn('industry');
        });
    }
};
