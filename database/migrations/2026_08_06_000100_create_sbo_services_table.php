<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The services a business offers, per listing.
 *
 * The profile page has shown three services since it was built — Renovation
 * Services, Land Acquisition, Interior Design — as a `const` array in
 * BusinessProfileView.vue, identical on all 900+ listings. This is where they
 * live now.
 *
 * Deliberately its own table rather than a JSON column on sbos: the count
 * feeds the "Specialties Offered" highlight, so it has to be aggregatable, and
 * the owner reorders them.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sbo_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            // Owner-defined display order; ties break on id.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['sbo_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_services');
    }
};
