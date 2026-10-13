<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loveworld church zones. Seeded from App\Support\ChurchZones, then managed by
 * admins. Each zone gets a unique short `code` used to build a referral link
 * (/join/{code}) that tags registrations to the zone and is emailed to the
 * zonal pastor.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable();
            // Public referral code embedded in the join link. Not a secret.
            $table->string('code')->unique();
            $table->string('pastor_name')->nullable();
            $table->string('pastor_email')->nullable();
            $table->boolean('is_active')->default(true);
            // When the pastor was last emailed their referral link.
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
