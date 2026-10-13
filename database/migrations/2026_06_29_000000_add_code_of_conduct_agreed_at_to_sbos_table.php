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
            // When the registrant read and agreed to the Kingdom Business Code of Conduct.
            $table->timestamp('code_of_conduct_agreed_at')->nullable()->after('resubmitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropColumn('code_of_conduct_agreed_at');
        });
    }
};
