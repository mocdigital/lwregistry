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
        Schema::create('states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnUpdate();
            $table->string('name', 100);
        });

        // Nigeria has ID 159 in the countries table
        DB::table('states')->insert([
            ['country_id' => 159, 'name' => 'Abia'],
            ['country_id' => 159, 'name' => 'Abuja (Federal Capital Territory)'],
            ['country_id' => 159, 'name' => 'Adamawa'],
            ['country_id' => 159, 'name' => 'Akwa Ibom'],
            ['country_id' => 159, 'name' => 'Anambra'],
            ['country_id' => 159, 'name' => 'Bauchi'],
            ['country_id' => 159, 'name' => 'Bayelsa'],
            ['country_id' => 159, 'name' => 'Benue'],
            ['country_id' => 159, 'name' => 'Borno'],
            ['country_id' => 159, 'name' => 'Cross River'],
            ['country_id' => 159, 'name' => 'Delta'],
            ['country_id' => 159, 'name' => 'Ebonyi'],
            ['country_id' => 159, 'name' => 'Edo'],
            ['country_id' => 159, 'name' => 'Ekiti'],
            ['country_id' => 159, 'name' => 'Enugu'],
            ['country_id' => 159, 'name' => 'Gombe'],
            ['country_id' => 159, 'name' => 'Imo'],
            ['country_id' => 159, 'name' => 'Jigawa'],
            ['country_id' => 159, 'name' => 'Kaduna'],
            ['country_id' => 159, 'name' => 'Kano'],
            ['country_id' => 159, 'name' => 'Katsina'],
            ['country_id' => 159, 'name' => 'Kebbi'],
            ['country_id' => 159, 'name' => 'Kogi'],
            ['country_id' => 159, 'name' => 'Kwara'],
            ['country_id' => 159, 'name' => 'Lagos'],
            ['country_id' => 159, 'name' => 'Nasarawa'],
            ['country_id' => 159, 'name' => 'Niger'],
            ['country_id' => 159, 'name' => 'Ogun'],
            ['country_id' => 159, 'name' => 'Ondo'],
            ['country_id' => 159, 'name' => 'Osun'],
            ['country_id' => 159, 'name' => 'Oyo'],
            ['country_id' => 159, 'name' => 'Plateau'],
            ['country_id' => 159, 'name' => 'Rivers'],
            ['country_id' => 159, 'name' => 'Sokoto'],
            ['country_id' => 159, 'name' => 'Taraba'],
            ['country_id' => 159, 'name' => 'Yobe'],
            ['country_id' => 159, 'name' => 'Zamfara'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
