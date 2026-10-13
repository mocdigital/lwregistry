<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the bid wizard actually collected.
 *
 * `purchase_order_bids` stores a single free-text `proposal`, so the five-step
 * wizard had to flatten contact details, address, service areas,
 * certifications, company profile and the signature into one blob. That is
 * readable by a human but structurally gone: the RFQ's Interest tab could not
 * render a submitted bid back, because there were no fields to render.
 *
 * JSON rather than thirty columns: the wizard's shape is a form definition
 * that will keep moving, and none of it is queried — it is displayed as a
 * whole against one bid. The columns that ARE queried (status, bidder,
 * submitted_at) already exist.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->json('details')->nullable()->after('proposal');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->dropColumn('details');
        });
    }
};
