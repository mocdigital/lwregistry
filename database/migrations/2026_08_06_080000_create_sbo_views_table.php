<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile views, per SBO.
 *
 * The profile's "View Analytics" card promises "Track views, clicks and leads"
 * and there was nothing to track them with: `business_views` belongs to the
 * legacy `Business` model, and `sbos` has no view counter at all.
 *
 * A row per view rather than a counter column, because the page shows a trend
 * — a single integer cannot say whether a listing is being found more this week
 * than last. `viewer_id` is nullable so a view is still counted if the viewer
 * cannot be identified, and the owner's own visits are not recorded at all.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sbo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            // Hashed, not stored raw: a view log is not a reason to keep a list
            // of who was where.
            $table->string('viewer_hash', 64)->nullable();
            $table->timestamp('viewed_at');

            $table->index(['sbo_id', 'viewed_at']);
            // One view per viewer per SBO per day, enforced on write.
            $table->index(['sbo_id', 'viewer_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbo_views');
    }
};
