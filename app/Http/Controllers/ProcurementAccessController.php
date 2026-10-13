<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAccessRequest;
use App\Models\PurchaseOrderInvite;
use App\Notifications\ProcurementAccessGrantedNotification;
use App\Notifications\ProcurementAccessRequestedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Asking to see a private procurement request, and the owner answering.
 *
 * A member who lands on the locked screen asks for access; the owner approves,
 * which invites them, or declines. Approving is the only thing that opens the
 * request — the link never does.
 */
final class ProcurementAccessController extends Controller
{
    /** A non-invited viewer asks to be let in. */
    public function request(Request $request, PurchaseOrder $procurement): RedirectResponse
    {
        $user = $request->user();

        // Already allowed, or the request is public — nothing to ask for.
        if ($procurement->isViewableBy($user)) {
            return redirect()->route('procurement.show', $procurement);
        }

        $access = PurchaseOrderAccessRequest::firstOrNew([
            'purchase_order_id' => $procurement->id,
            'user_id' => $user->id,
        ]);

        // A fresh ask, or a previously declined one raised again — either way it
        // goes back to pending and the owner is told once.
        if (PurchaseOrderAccessRequest::STATUS_PENDING !== $access->status || ! $access->exists) {
            $access->fill(['status' => PurchaseOrderAccessRequest::STATUS_PENDING, 'responded_at' => null])->save();

            $procurement->sbo?->creator?->notify(
                new ProcurementAccessRequestedNotification($procurement, $user),
            );
        }

        return back()->with('status', 'access-requested');
    }

    public function approve(Request $request, PurchaseOrder $procurement, PurchaseOrderAccessRequest $accessRequest): RedirectResponse
    {
        $this->authorizeOwner($request, $procurement);
        abort_if($accessRequest->purchase_order_id !== $procurement->id, 404);

        $accessRequest->update([
            'status' => PurchaseOrderAccessRequest::STATUS_APPROVED,
            'responded_at' => now(),
        ]);

        // Approving grants access by inviting the requester.
        $procurement->invites()->firstOrCreate(
            ['invited_user_id' => $accessRequest->user_id],
            ['invited_by_sbo_id' => $procurement->sbo_id, 'status' => PurchaseOrderInvite::STATUS_ACCEPTED],
        );

        $accessRequest->user?->notify(new ProcurementAccessGrantedNotification($procurement));

        return back()->with('status', 'access-approved');
    }

    public function decline(Request $request, PurchaseOrder $procurement, PurchaseOrderAccessRequest $accessRequest): RedirectResponse
    {
        $this->authorizeOwner($request, $procurement);
        abort_if($accessRequest->purchase_order_id !== $procurement->id, 404);

        $accessRequest->update([
            'status' => PurchaseOrderAccessRequest::STATUS_DECLINED,
            'responded_at' => now(),
        ]);

        return back()->with('status', 'access-declined');
    }

    private function authorizeOwner(Request $request, PurchaseOrder $procurement): void
    {
        abort_unless(
            $request->user()->sbos()->pluck('id')->contains($procurement->sbo_id),
            403,
        );
    }
}
