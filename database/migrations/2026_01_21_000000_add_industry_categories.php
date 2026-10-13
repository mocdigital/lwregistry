<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class() extends Migration
{
    /**
     * Industry categories requested for the SBO directory.
     *
     * @var array<int, string>
     */
    private array $categories = [
        'Manufacturing',
        'Education',
        'FMCG',
        'Aviation',
        'Entertainment',
        'Arts',
        'Media',
    ];

    public function up(): void
    {
        foreach ($this->categories as $name) {
            $slug = Str::slug($name);

            // Idempotent: skip if a category with this slug already exists.
            $exists = DB::table('categories')->where('slug', $slug)->exists();

            if ( ! $exists) {
                DB::table('categories')->insert([
                    'name' => $name,
                    'slug' => $slug,
                    'business_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->categories as $name) {
            DB::table('categories')->where('slug', Str::slug($name))->delete();
        }
    }
};
