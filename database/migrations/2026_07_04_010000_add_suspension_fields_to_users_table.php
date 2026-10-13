<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->after('email_verified_at');
            $table->foreignId('suspended_by')
                ->nullable()
                ->after('suspended_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('suspension_reason')->nullable()->after('suspended_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['suspended_by']);
            $table->dropColumn(['suspended_at', 'suspended_by', 'suspension_reason']);
        });
    }
};
