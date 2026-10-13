<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A testimonial from the business that won a procurement bid. Written after the
 * award, it carries the winner's own words plus whether they agreed to have it
 * shown on the platform's public pages — a declined one is kept for the record
 * but never displayed.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bid_id')->nullable()->constrained('purchase_order_bids')->nullOnDelete();
            $table->foreignId('sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('headline');
            $table->text('body');
            // The winner's consent to show this on public pages (home carousel,
            // the awarded-bids page). False keeps it private — recorded, not shown.
            $table->boolean('display_publicly')->default(false);
            $table->timestamps();

            // One testimonial per won request per winning business.
            $table->unique(['purchase_order_id', 'sbo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
