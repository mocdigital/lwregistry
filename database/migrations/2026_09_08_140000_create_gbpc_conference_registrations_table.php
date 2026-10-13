<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Registrations submitted from the GBPC landing page (gbpc.lwregistry.org).
 * The page is a static site on another host; it POSTs here cross-origin, and
 * admins review the submissions from the backend.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('gbpc_conference_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('country');
            $table->string('phone');
            $table->string('kingschat_handle')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbpc_conference_registrations');
    }
};
