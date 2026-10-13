<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('sbo_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requester_sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->foreignId('recipient_sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['requester_sbo_id', 'recipient_sbo_id']);
            $table->index(['recipient_sbo_id', 'status']);
        });

        Schema::create('sbo_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->nullable()->constrained('sbo_connections')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->string('type')->default('direct')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('sbo_conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('sbo_conversations')->cascadeOnDelete();
            $table->foreignId('sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'sbo_id']);
            $table->index(['sbo_id', 'last_read_at']);
        });

        Schema::create('sbo_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('sbo_conversations')->cascadeOnDelete();
            $table->foreignId('sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['sbo_id', 'created_at']);
        });

        Schema::create('purchase_order_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->foreignId('invited_by_sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'invited_sbo_id']);
            $table->index(['invited_sbo_id', 'status']);
        });

        Schema::create('sbo_network_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reporter_sbo_id')->constrained('sbos')->cascadeOnDelete();
            $table->foreignId('reported_sbo_id')->nullable()->constrained('sbos')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('sbo_messages')->nullOnDelete();
            $table->string('reason');
            $table->text('details')->nullable();
            $table->string('status')->default('open')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('visibility')->default('public')->after('status')->index();
        });

        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->unsignedBigInteger('business_id')->nullable()->change();
            $table->foreignId('bidder_sbo_id')
                ->nullable()
                ->after('business_id')
                ->constrained('sbos')
                ->nullOnDelete();
            $table->index(['purchase_order_id', 'bidder_sbo_id']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_bids', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bidder_sbo_id');
            $table->unsignedBigInteger('business_id')->nullable(false)->change();
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn('visibility');
        });

        Schema::dropIfExists('sbo_network_reports');
        Schema::dropIfExists('purchase_order_invites');
        Schema::dropIfExists('sbo_messages');
        Schema::dropIfExists('sbo_conversation_participants');
        Schema::dropIfExists('sbo_conversations');
        Schema::dropIfExists('sbo_connections');
    }
};
