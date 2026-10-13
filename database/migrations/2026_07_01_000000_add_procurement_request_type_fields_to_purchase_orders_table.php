<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('request_type')->default('product')->after('category_id');
            $table->string('unit_specification')->nullable()->after('quantity');
            $table->string('delivery_location')->nullable()->after('unit_specification');
            $table->text('service_scope')->nullable()->after('delivery_location');
            $table->string('service_duration')->nullable()->after('service_scope');
            $table->string('required_expertise')->nullable()->after('service_duration');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'request_type',
                'unit_specification',
                'delivery_location',
                'service_scope',
                'service_duration',
                'required_expertise',
            ]);
        });
    }
};
