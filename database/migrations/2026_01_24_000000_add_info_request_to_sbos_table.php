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
            // Message from the reviewer describing what info is needed.
            $table->text('info_request')->nullable()->after('decline_reason');
            // When the applicant last resubmitted after an info request.
            $table->timestamp('resubmitted_at')->nullable()->after('info_request');
        });
    }

    public function down(): void
    {
        Schema::table('sbos', function (Blueprint $table): void {
            $table->dropColumn(['info_request', 'resubmitted_at']);
        });
    }
};
