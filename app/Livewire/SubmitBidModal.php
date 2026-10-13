<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\PurchaseOrderInvite;
use App\Models\Sbo;
use App\Support\SboNetworkAccess;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

final class SubmitBidModal extends Component
{
    use WithFileUploads;

    public ?PurchaseOrder $rfq = null;

    public $bidder_sbo_id = null;

    public $proposal = '';

    public $attachment = null;

    public bool $alreadyBid = false;

    #[On('open-submit-bid')]
    public function open(int $rfqId): void
    {
        $this->ensureWorkspaceAccess();

        $verifiedSboIds = app(SboNetworkAccess::class)->verifiedSboIdsFor(auth()->user());

        $this->rfq = PurchaseOrder::with(['category', 'sbo', 'invites'])
            ->visibleToAnySbo($verifiedSboIds)
            ->findOrFail($rfqId);

        $this->alreadyBid = PurchaseOrderBid::where('purchase_order_id', $rfqId)
            ->where(function ($query) use ($verifiedSboIds): void {
                $query->where('user_id', auth()->id())
                    ->orWhereIn('bidder_sbo_id', $verifiedSboIds);
            })
            ->exists();

        $this->resetValidation();
        $this->reset(['bidder_sbo_id', 'proposal', 'attachment']);

        $this->dispatch('open-submit-bid-modal');
    }

    public function save(): void
    {
        $this->ensureWorkspaceAccess();

        if ( ! $this->rfq) {
            $this->addError('bidder_sbo_id', 'Please select a valid procurement request before submitting a bid.');

            return;
        }

        $verifiedSboIds = app(SboNetworkAccess::class)->verifiedSboIdsFor(auth()->user());

        $this->validate([
            'bidder_sbo_id' => ['required', 'integer', Rule::in($verifiedSboIds)],
            'proposal' => ['required', 'string', 'min:20'],
            'attachment' => ['required', 'file', 'max:10240'],
        ], [
            'proposal.required' => 'Please provide your proposal details.',
            'attachment.required' => 'Please upload a supporting proposal document.',
            'bidder_sbo_id.required' => 'Choose the verified SBO submitting this bid.',
            'bidder_sbo_id.in' => 'Only your fully verified, active SBOs can submit bids.',
        ]);

        $bidderSbo = Sbo::findOrFail((int) $this->bidder_sbo_id);

        $this->rfq->refresh();

        if ( ! $this->rfq->canSboBid($bidderSbo)) {
            $this->addError('bidder_sbo_id', 'This SBO cannot submit a bid for this request. It may be closed, already submitted, owned by you, or invite-only.');

            return;
        }

        $path = $this->attachment->store('rfq-bids', 'public');

        PurchaseOrderBid::create([
            'purchase_order_id' => $this->rfq->id,
            'user_id' => auth()->id(),
            'business_id' => null,
            'bidder_sbo_id' => $bidderSbo->id,
            'proposal' => $this->proposal,
            'attachment_path' => $path,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        PurchaseOrderInvite::where('purchase_order_id', $this->rfq->id)
            ->where('invited_sbo_id', $bidderSbo->id)
            ->where('status', PurchaseOrderInvite::STATUS_PENDING)
            ->update([
                'status' => PurchaseOrderInvite::STATUS_ACCEPTED,
                'responded_at' => now(),
            ]);

        session()->flash('success', 'Bid submitted successfully.');

        $this->dispatch('bid-submitted');
    }

    public function render()
    {
        $verifiedSbos = collect();

        if (auth()->check()) {
            $verifiedSbos = app(SboNetworkAccess::class)
                ->verifiedSbosFor(auth()->user())
                ->filter(fn (Sbo $sbo): bool => $this->rfq?->isVisibleToSbo($sbo) ?? true)
                ->values();
        }

        return view('livewire.submit-bid-modal', [
            'verifiedSbos' => $verifiedSbos,
        ])->layout(null);
    }

    private function ensureWorkspaceAccess(): void
    {
        $user = auth()->user();

        if ( ! $user || ! app(SboNetworkAccess::class)->userCanUseNetwork($user)) {
            abort(403, 'Only fully verified, active SBOs can submit bids.');
        }
    }
}
