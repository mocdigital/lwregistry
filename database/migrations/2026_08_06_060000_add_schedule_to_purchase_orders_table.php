<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The RFP schedule — the dates a bidder plans around.
 *
 * A procurement request carried one date, `order_deadline`, so everything
 * between advertising the request and announcing the award was unstated: when
 * the briefing is, whether it is mandatory, how long questions stay open, when
 * answers come back, and when a decision is expected.
 *
 * `order_deadline` stays the proposals-due date rather than gaining a twin.
 * Two columns both meaning "the last moment to bid" is how they end up
 * disagreeing, and it is already what "Closing Soon" and the bid endpoint read.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->date('advertised_at')->nullable()->after('order_deadline');

            // Pre-proposal briefing. Time matters for a meeting, unlike the
            // rest of the schedule, so this one is a full timestamp.
            $table->dateTime('briefing_at')->nullable()->after('advertised_at');
            $table->string('briefing_link')->nullable()->after('briefing_at');
            $table->string('briefing_location')->nullable()->after('briefing_link');
            // The design labels it "(Non-Mandatory)", which is the default
            // rather than the only possibility.
            $table->boolean('briefing_mandatory')->default(false)->after('briefing_location');

            // "Inquiry period" is a duration, so it is two dates.
            $table->date('inquiry_opens_at')->nullable()->after('briefing_mandatory');
            $table->date('inquiry_closes_at')->nullable()->after('inquiry_opens_at');
            $table->date('inquiry_responses_at')->nullable()->after('inquiry_closes_at');

            $table->date('final_selection_at')->nullable()->after('inquiry_responses_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'advertised_at',
                'briefing_at',
                'briefing_link',
                'briefing_location',
                'briefing_mandatory',
                'inquiry_opens_at',
                'inquiry_closes_at',
                'inquiry_responses_at',
                'final_selection_at',
            ]);
        });
    }
};
