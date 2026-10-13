<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('business_count')->default(0);
            $table->timestamps();
        });

        // Insert all 32 categories from Popular Business Categories
        DB::table('categories')->insert([
            // Column 1
            ['name' => 'Churches & Ministries', 'slug' => 'churches-ministries', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Faith-Based Schools', 'slug' => 'faith-based-schools', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Restaurants & Cafés', 'slug' => 'restaurants-cafes', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Hotels & Hospitality', 'slug' => 'hotels-hospitality', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Construction & Real Estate', 'slug' => 'construction-real-estate', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Technology Services', 'slug' => 'technology-services', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Health & Medical Services', 'slug' => 'health-medical-services', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Legal Consultants', 'slug' => 'legal-consultants', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],

            // Column 2
            ['name' => 'Finance & Banking', 'slug' => 'finance-banking', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bookstores & Publishers', 'slug' => 'bookstores-publishers', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Transportation & Logistics', 'slug' => 'transportation-logistics', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Events & Conferences', 'slug' => 'events-conferences', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Fashion & Apparel', 'slug' => 'fashion-apparel', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Graphics & Printing', 'slug' => 'graphics-printing', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Media & Communications', 'slug' => 'media-communications', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Education & Training', 'slug' => 'education-training', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],

            // Column 3
            ['name' => 'Consulting & Advisory', 'slug' => 'consulting-advisory', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Interior Design', 'slug' => 'interior-design', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Cleaning & Maintenance', 'slug' => 'cleaning-maintenance', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Engineering & Manufacturing', 'slug' => 'engineering-manufacturing', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Beauty & Personal Care', 'slug' => 'beauty-personal-care', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Agriculture & Agro Trade', 'slug' => 'agriculture-agro-trade', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Software Development', 'slug' => 'software-development', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Non-Profits & NGOs', 'slug' => 'non-profits-ngos', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],

            // Column 4
            ['name' => 'Music & Entertainment', 'slug' => 'music-entertainment', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Photography & Media', 'slug' => 'photography-media', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Marketing & Advertising', 'slug' => 'marketing-advertising', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Retail & Wholesale', 'slug' => 'retail-wholesale', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Travel & Tourism', 'slug' => 'travel-tourism', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Sports & Recreation', 'slug' => 'sports-recreation', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Faith-Based NGOs', 'slug' => 'faith-based-ngos', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Childcare & Family Services', 'slug' => 'childcare-family-services', 'business_count' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
