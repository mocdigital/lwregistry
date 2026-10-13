<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ministries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The SBO's affiliated ministry, chosen at registration. Free text
        // rather than a foreign key so renaming or removing a ministry never
        // orphans a listing — the same treatment as the category name.
        Schema::table('sbos', function (Blueprint $table): void {
            $table->string('ministry')->nullable()->after('category');
        });

        /*
         * A starting set of LoveWorld ministry arms so the registration
         * dropdown is not empty on day one. Admins manage this list from the
         * backend — add, rename, deactivate — so nothing here is fixed.
         */
        $now = now();
        $seed = [
            'Christ Embassy (LoveWorld Inc.)',
            'InnerCity Mission for Children',
            'Healing School',
            'Rhapsody of Realities',
            'LoveWorld Television Ministry',
            'LoveWorld Music and Arts Ministry',
            'Believers LoveWorld',
        ];

        DB::table('ministries')->insert(array_map(fn (string $name): array => [
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $seed));
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropColumn('ministry');
        });

        Schema::dropIfExists('ministries');
    }
};
