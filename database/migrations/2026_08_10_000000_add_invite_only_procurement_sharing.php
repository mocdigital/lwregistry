<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invite-only procurement sharing.
 *
 * The invites table only knew how to invite an SBO. A request's owner also
 * wants to invite a platform user directly, or an email address that may not be
 * a member yet — so `invited_sbo_id` becomes optional and two more targets sit
 * beside it. And someone who reaches a private request they were not invited to
 * can ask for access, which is the access-requests table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_invites', function (Blueprint $table): void {
            // An invite now targets exactly one of: an SBO, a user, or an email.
            $table->foreignId('invited_sbo_id')->nullable()->change();
            $table->foreignId('invited_user_id')->nullable()->after('invited_sbo_id')
                ->constrained('users')->cascadeOnDelete();
            $table->string('invited_email')->nullable()->after('invited_user_id');

            $table->index('invited_user_id');
            $table->index('invited_email');
        });

        Schema::create('purchase_order_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // pending → approved | declined.
            $table->string('status')->default('pending')->index();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            // One live request per person per RFQ.
            $table->unique(['purchase_order_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_access_requests');

        Schema::table('purchase_order_invites', function (Blueprint $table): void {
            $table->dropIndex(['invited_email']);
            $table->dropIndex(['invited_user_id']);
            $table->dropConstrainedForeignId('invited_user_id');
            $table->dropColumn('invited_email');
        });
    }
};
