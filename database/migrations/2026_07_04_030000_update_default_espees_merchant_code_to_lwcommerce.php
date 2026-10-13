<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        DB::table('sbo_fee_settings')
            ->where('key', 'merchant_name')
            ->where('value', 'MOCDII1')
            ->update(['value' => 'LWCOMMERCE']);
    }

    public function down(): void
    {
        DB::table('sbo_fee_settings')
            ->where('key', 'merchant_name')
            ->where('value', 'LWCOMMERCE')
            ->update(['value' => 'MOCDII1']);
    }
};
