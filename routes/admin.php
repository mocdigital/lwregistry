<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ChatbotBlueprintController;
use App\Http\Controllers\Admin\ChatbotLiveController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['web', 'auth', 'verified', 'admin', 'admin_page'])
    ->prefix('admin')
    ->group(function (): void {
        Volt::route('/', 'admin.dashboard')->name('admin.dashboard');
        Volt::route('/users', 'admin.users.index')->name('admin.users.index');
        Volt::route('/businesses', 'admin.businesses.index')->name('admin.businesses.index');
        // Export the (scoped, filtered) SBO list — same controller as the
        // Organizations screen; both pages list the same businesses.
        Route::get('/businesses/export', App\Http\Controllers\Admin\BusinessExportController::class)
            ->name('admin.businesses.export');
        // The COB (Citizen Owned Business) half of the directory — the same
        // listing as /businesses, scoped to entity_type = COB.
        Volt::route('/cob', 'admin.cob.index')->name('admin.cob.index');
        Volt::route('/activity', 'admin.activity-logs')->name('admin.activity.index');
        Volt::route('/categories-locations', 'admin.categories-locations')->name('admin.categories-locations.index');
        // Admin-managed ministry list, offered as a dropdown at registration.
        Volt::route('/ministries', 'admin.ministries')->name('admin.ministries.index');
        // Conference registrations collected from the GBPC landing page.
        Volt::route('/gbpc-registrations', 'admin.gbpc-registrations')->name('admin.gbpc-registrations.index');
        Route::get('/gbpc-registrations/export', App\Http\Controllers\Admin\GbpcRegistrationExportController::class)
            ->name('admin.gbpc-registrations.export');
        Volt::route('/businesses/{business}', 'admin.businesses.show')->name('admin.businesses.show');
        Volt::route('/organizations', 'admin.sbos.index')->name('admin.sbos.index');
        // Export the (scoped, filtered) business list as CSV, Excel or PDF.
        Route::get('/organizations/export', App\Http\Controllers\Admin\BusinessExportController::class)
            ->name('admin.sbos.export');
        Volt::route('/approvals', 'admin.approvals.index')->name('admin.approvals.index');
        Route::redirect('/approvals/payments', '/admin/approvals')->name('admin.approvals.payments');
        Route::redirect('/approvals/documents', '/admin/approvals')->name('admin.approvals.documents');
        Route::redirect('/approvals/final-verification', '/admin/approvals')->name('admin.approvals.final-verification');
        Volt::route('/approvals/info-requests', 'admin.approvals.info-requests')->name('admin.approvals.info-requests');
        Volt::route('/approvals/rejected-history', 'admin.approvals.rejected-history')->name('admin.approvals.rejected-history');
        Volt::route('/sbos/{sbo}', 'admin.sbos.show')->name('admin.sbos.show');
        Volt::route('/compliance/documents', 'admin.compliance.documents')->name('admin.compliance.documents.index');
        Volt::route('/fees', 'admin.fees.index')->name('admin.fees.index');
        Volt::route('/zones/referrals', 'admin.zones.referrals')->name('admin.zones.referrals.index');
        Volt::route('/zones/momentum', 'admin.zones.momentum')->name('admin.zones.momentum.index');
        Volt::route('/growth/payments', 'admin.growth.payments')->name('admin.growth.payments');
        Volt::route('/reports', 'admin.reports.index')->name('admin.reports.index');
        /*
         * The blueprint canvas talks to these directly. A node editor sends a
         * whole graph on save, and routing that through Livewire state would
         * mean every drag round-tripping to the server.
         */
        Route::get('/chatbot/blueprints/{blueprint}', [ChatbotBlueprintController::class, 'show'])
            ->name('admin.chatbot.blueprints.show');
        Route::put('/chatbot/blueprints/{blueprint}', [ChatbotBlueprintController::class, 'save'])
            ->name('admin.chatbot.blueprints.save');

        // The sales assistant's knowledge bank, and what it could not answer.
        Volt::route('/assistant', 'admin.assistant.index')->name('admin.assistant.index');
        // The conversation itself, drawn as nodes and wires.
        Volt::route('/assistant/blueprints', 'admin.chatbot.blueprints')->name('admin.chatbot.blueprints');

        // The live console — watching conversations and taking them over. The
        // page is a Volt shell; its list, transcript and actions are JSON the
        // console polls, because "live" here is a short poll (no websocket
        // server on this deployment).
        Volt::route('/assistant/live', 'admin.assistant.live')->name('admin.assistant.live');
        Route::post('/assistant/live/ping', [ChatbotLiveController::class, 'ping'])->name('admin.assistant.live.ping');
        Route::get('/assistant/live/sessions', [ChatbotLiveController::class, 'sessions'])->name('admin.assistant.live.sessions');
        Route::get('/assistant/live/thread/{session}', [ChatbotLiveController::class, 'thread'])->name('admin.assistant.live.thread');
        Route::post('/assistant/live/takeover/{session}', [ChatbotLiveController::class, 'takeover'])->name('admin.assistant.live.takeover');
        Route::post('/assistant/live/send/{session}', [ChatbotLiveController::class, 'send'])->name('admin.assistant.live.send');
        Route::post('/assistant/live/end/{session}', [ChatbotLiveController::class, 'end'])->name('admin.assistant.live.end');

        // Private threads between a member and the registry; the other end of
        // the member's "Message admin".
        Volt::route('/messages', 'admin.messages.index')->name('admin.messages.index');
        Volt::route('/support', 'admin.support.index')->name('admin.support.index');
        Volt::route('/support/feedback', 'admin.support.feedback')->name('admin.support.feedback');
        Volt::route('/support/network-reports', 'admin.network.reports')->name('admin.network.reports');
        // Industry group chats — the rooms members are placed in on verification.
        Volt::route('/support/industry-groups', 'admin.network.groups')->name('admin.network.groups');
        Volt::route('/purchase-orders', 'admin.purchase-orders.index')->name('admin.purchase-orders.index');
        Volt::route('/purchase-orders/{purchaseOrder}', 'admin.purchase-orders.show')->name('admin.purchase-orders.show');
        Volt::route('/inventory', 'admin.inventory.index')->name('admin.inventory.index');
        Volt::route('/inventory/orders', 'admin.inventory.orders')->name('admin.inventory.orders.index');
        Volt::route('/inventory/order-details', 'admin.inventory.order-details')->name('admin.inventory.order-details.index');
        Volt::route('/admins', 'admin.admins.index')->name('admin.admins.index');
        Volt::route('/ads', 'admin.ads.index')->name('admin.ads.index');
        // The public Advertise form has always written to this table; nothing read it.
        Volt::route('/ads/inquiries', 'admin.ads.inquiries')->name('admin.ads.inquiries');
        Volt::route('/subscribers', 'admin.subscribers.index')->name('admin.subscribers.index');
        Volt::route('/mail-test', 'admin.mail-test')->name('admin.mail-test');
    });
