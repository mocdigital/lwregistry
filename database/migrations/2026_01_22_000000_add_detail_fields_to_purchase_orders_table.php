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
            $table->string('quantity')->nullable()->after('budget');
            $table->text('required_documents')->nullable()->after('quantity');
            $table->text('terms')->nullable()->after('required_documents');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['quantity', 'required_documents', 'terms']);
        });
    }
};
