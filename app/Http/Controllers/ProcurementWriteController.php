<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\PurchaseOrderDocument;
use App\Models\PurchaseOrderInvite;
use App\Models\PurchaseOrderQuestion;
use App\Models\Sbo;
use App\Models\Testimonial;
use App\Models\User;
use App\Notifications\ProcurementInvitedNotification;
use App\Notifications\SubmitTestimonialNotification;
use App\Rules\InvitedBidderEmail;
use App\Support\RichText;
use App\Support\SboNetworkAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The procurement writes. Separated from ProcurementController so the read
 * screens stay easy to follow.
 *
 * Ownership rules: you post an RFQ as one of your own SBOs, you cannot bid on
 * your own RFQ, and only the RFQ's owner can award it. Those are enforced here
 * rather than left to the UI, which is the only place they were enforced
 * before — the forms simply had nowhere to post.
 */
final class ProcurementWriteController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $sboIds = $request->user()->sbos()->pluck('id');

        $data = $request->validate([
            'sbo_id' => ['required', Rule::in($sboIds)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'category' => ['required', 'string', Rule::exists(Category::class, 'name')],
            'request_type' => ['required', Rule::in(['product', 'service', 'both'])],
            'budget' => ['nullable', 'string', 'max:255'],
            /*
             * Required once an amount is given: a number with no currency is
             * not a budget on a registry that spans several countries and
             * prices its own fees in Espees.
             */
            'budget_currency' => [
                'nullable',
                'required_with:budget',
                Rule::in(PurchaseOrder::CURRENCIES),
            ],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'delivery_location' => ['nullable', 'string', 'max:255'],
            // Proposals due. The one date the RFQ already had.
            'order_deadline' => ['nullable', 'date', 'after:today'],

            /*
             * The rest of the RFP schedule. Every date is optional — a simple
             * request for quotes does not need a briefing or an inquiry window
             * — but the ones given have to run in order, because a schedule
             * that answers questions before they can be asked is worse than no
             * schedule at all.
             */
            'advertised_at' => ['nullable', 'date'],
            'briefing_at' => ['nullable', 'date', 'after_or_equal:advertised_at'],
            'briefing_link' => ['nullable', 'url', 'max:255'],
            'briefing_location' => ['nullable', 'string', 'max:255'],
            'briefing_mandatory' => ['nullable', 'boolean'],
            'inquiry_opens_at' => ['nullable', 'date', 'after_or_equal:advertised_at'],
            'inquiry_closes_at' => ['nullable', 'date', 'after_or_equal:inquiry_opens_at'],
            'inquiry_responses_at' => ['nullable', 'date', 'after_or_equal:inquiry_closes_at'],
            // A proposal cannot be due before the answers arrive.
            'final_selection_at' => ['nullable', 'date', 'after:today', 'after_or_equal:order_deadline'],
            'visibility' => ['required', Rule::in([
                PurchaseOrder::VISIBILITY_PUBLIC, PurchaseOrder::VISIBILITY_INVITE_ONLY,
            ])],
            'terms' => ['nullable', 'string'],
            /*
             * The photo the opportunity cards draw. Without it the cards fell
             * back to one of three stock Figma photos chosen by list position,
             * so a road-construction shot could illustrate a request for
             * accountancy services.
             */
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            /*
             * Additional files. The form offered one upload, while the detail
             * screen's Documents tab drew two whole sections of them — so what
             * a buyer could attach and what a bidder was shown never matched.
             */
            'documents' => ['nullable', 'array', 'max:20'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
            // Who a private request is shared with — by email or by business.
            'invites_emails' => ['nullable', 'array', 'max:50'],
            'invites_emails.*' => ['email', 'max:255', new InvitedBidderEmail()],
            'invites_sbo_ids' => ['nullable', 'array', 'max:50'],
            'invites_sbo_ids.*' => ['integer', 'exists:sbos,id'],
        ]);

        /*
         * `category` and `document` are inputs, not columns — the category is
         * resolved to `category_id` and the file to `document_path`. Passing
         * the raw validated array to the constructor threw
         * MassAssignmentException on both, so nothing could be posted here at
         * all.
         */
        // Answers must not be promised before the questions close, and a
        // proposal must not be due before the answers land.
        if (filled($data['inquiry_responses_at'] ?? null) && filled($data['order_deadline'] ?? null)
            && $data['inquiry_responses_at'] > $data['order_deadline']) {
            throw ValidationException::withMessages([
                'order_deadline' => 'Proposals cannot be due before responses to inquiries are sent.',
            ]);
        }

        /*
         * The description and terms come from a rich-text field, so they are
         * markup written by one business and read by every other. Sanitised
         * here rather than in the editor: an editor restricts what a person
         * can TYPE, not what can be posted to this endpoint.
         */
        $data['description'] = RichText::clean($data['description']);
        $data['terms'] = RichText::clean($data['terms'] ?? null) ?: null;

        // Markup with no words in it is not a description.
        if (RichText::isEmpty($data['description'])) {
            throw ValidationException::withMessages([
                'description' => 'Describe what you need vendors to quote for.',
            ]);
        }

        $order = new PurchaseOrder(Arr::except($data, ['category', 'document', 'documents', 'featured_image', 'invites_emails', 'invites_sbo_ids']));
        $order->category_id = Category::where('name', $data['category'])->value('id');
        $order->status = 'open';

        if ($request->hasFile('document')) {
            $order->document_path = $request->file('document')->store('procurement', 'public');
        }

        $order->save();

        // The card photo. Attached after save because media needs an id.
        if ($request->hasFile('featured_image')) {
            $order->addMediaFromRequest('featured_image')
                ->toMediaCollection(PurchaseOrder::FEATURED_COLLECTION);
        }

        foreach ((array) $request->file('documents') as $file) {
            PurchaseOrderDocument::create([
                'purchase_order_id' => $order->id,
                'section' => PurchaseOrderDocument::SECTION_PROJECT,
                'name' => $file->getClientOriginalName(),
                'path' => $file->store('procurement-documents', 'public'),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        $this->syncInvites($order, $data['invites_emails'] ?? [], $data['invites_sbo_ids'] ?? []);

        return redirect()->route('procurement.show', $order)->with('status', 'rfq-created');
    }

    /**
     * Save a request as a draft, to finish later.
     *
     * Only a title is required — everything else can be blank while the buyer is
     * still working — so the rules are the create rules with the requireds
     * loosened and the future-date checks dropped. A draft is status 'draft', so
     * it never appears in the public list and cannot be bid on until published.
     * The same endpoint updates an existing draft (via `id`), so "continue where
     * you left off" is one save on top of the last.
     */
    public function draft(Request $request): RedirectResponse
    {
        $sboIds = $request->user()->sbos()->pluck('id');

        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'sbo_id' => ['required', Rule::in($sboIds)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'category' => ['nullable', 'string', Rule::exists(Category::class, 'name')],
            'request_type' => ['nullable', Rule::in(['product', 'service', 'both'])],
            'budget' => ['nullable', 'string', 'max:255'],
            'budget_currency' => ['nullable', Rule::in(PurchaseOrder::CURRENCIES)],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'delivery_location' => ['nullable', 'string', 'max:255'],
            'order_deadline' => ['nullable', 'date'],
            'advertised_at' => ['nullable', 'date'],
            'briefing_at' => ['nullable', 'date'],
            'briefing_link' => ['nullable', 'url', 'max:255'],
            'briefing_location' => ['nullable', 'string', 'max:255'],
            'briefing_mandatory' => ['nullable', 'boolean'],
            'inquiry_opens_at' => ['nullable', 'date'],
            'inquiry_closes_at' => ['nullable', 'date'],
            'inquiry_responses_at' => ['nullable', 'date'],
            'final_selection_at' => ['nullable', 'date'],
            'visibility' => ['nullable', Rule::in([
                PurchaseOrder::VISIBILITY_PUBLIC, PurchaseOrder::VISIBILITY_INVITE_ONLY,
            ])],
            'terms' => ['nullable', 'string'],
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents' => ['nullable', 'array', 'max:20'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
            // Invites are not verified on a draft — the buyer may still be
            // deciding; the check runs when the request is published.
            'invites_emails' => ['nullable', 'array', 'max:50'],
            'invites_emails.*' => ['email', 'max:255'],
            'invites_sbo_ids' => ['nullable', 'array', 'max:50'],
            'invites_sbo_ids.*' => ['integer', 'exists:sbos,id'],
        ]);

        // Continue an existing draft, or start a new one. Only the owner's own,
        // and only while it is still a draft.
        $order = filled($data['id'] ?? null)
            ? PurchaseOrder::query()->find($data['id'])
            : null;

        if (null !== $order) {
            abort_unless($sboIds->contains($order->sbo_id), 403);
            abort_unless('draft' === $order->status, 403, 'This request is no longer a draft.');
        }

        $order ??= new PurchaseOrder();

        $order->fill(Arr::except($data, [
            'id', 'category', 'document', 'documents', 'featured_image', 'invites_emails', 'invites_sbo_ids',
        ]));
        $order->category_id = filled($data['category'] ?? null)
            ? Category::where('name', $data['category'])->value('id')
            : null;
        $order->status = 'draft';

        // These columns are NOT NULL; a draft may not have filled them, so they
        // take harmless placeholders until the request is published.
        $order->description ??= '';
        $order->request_type ??= 'product';
        $order->visibility ??= PurchaseOrder::VISIBILITY_PUBLIC;

        if ($request->hasFile('document')) {
            $order->document_path = $request->file('document')->store('procurement', 'public');
        }

        $order->save();

        if ($request->hasFile('featured_image')) {
            $order->clearMediaCollection(PurchaseOrder::FEATURED_COLLECTION);
            $order->addMediaFromRequest('featured_image')->toMediaCollection(PurchaseOrder::FEATURED_COLLECTION);
        }

        foreach ((array) $request->file('documents') as $file) {
            PurchaseOrderDocument::create([
                'purchase_order_id' => $order->id,
                'section' => PurchaseOrderDocument::SECTION_PROJECT,
                'name' => $file->getClientOriginalName(),
                'path' => $file->store('procurement-documents', 'public'),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        $this->syncInvites($order, $data['invites_emails'] ?? [], $data['invites_sbo_ids'] ?? [], notify: false);

        return redirect()->route('procurement.edit', $order)->with('status', 'draft-saved');
    }

    /**
     * Amend a request already posted. Owner only.
     *
     * The same fields as store, except the deadline is not forced into the
     * future — a live request being tidied up may already carry a date set when
     * it was created. New files are added; an existing one is only replaced when
     * a new one is uploaded, so editing the text does not wipe the attachments.
     */
    public function update(Request $request, PurchaseOrder $procurement): RedirectResponse
    {
        $sboIds = $request->user()->sbos()->pluck('id');

        abort_unless($sboIds->contains($procurement->sbo_id), 403);

        $data = $request->validate([
            'sbo_id' => ['required', Rule::in($sboIds)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'category' => ['required', 'string', Rule::exists(Category::class, 'name')],
            'request_type' => ['required', Rule::in(['product', 'service', 'both'])],
            'budget' => ['nullable', 'string', 'max:255'],
            'budget_currency' => ['nullable', 'required_with:budget', Rule::in(PurchaseOrder::CURRENCIES)],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'delivery_location' => ['nullable', 'string', 'max:255'],
            'order_deadline' => ['nullable', 'date', 'after:today'],
            'advertised_at' => ['nullable', 'date'],
            'briefing_at' => ['nullable', 'date', 'after_or_equal:advertised_at'],
            'briefing_link' => ['nullable', 'url', 'max:255'],
            'briefing_location' => ['nullable', 'string', 'max:255'],
            'briefing_mandatory' => ['nullable', 'boolean'],
            'inquiry_opens_at' => ['nullable', 'date', 'after_or_equal:advertised_at'],
            'inquiry_closes_at' => ['nullable', 'date', 'after_or_equal:inquiry_opens_at'],
            'inquiry_responses_at' => ['nullable', 'date', 'after_or_equal:inquiry_closes_at'],
            'final_selection_at' => ['nullable', 'date', 'after:today', 'after_or_equal:order_deadline'],
            'visibility' => ['required', Rule::in([
                PurchaseOrder::VISIBILITY_PUBLIC, PurchaseOrder::VISIBILITY_INVITE_ONLY,
            ])],
            'terms' => ['nullable', 'string'],
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'documents' => ['nullable', 'array', 'max:20'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
            'invites_emails' => ['nullable', 'array', 'max:50'],
            'invites_emails.*' => ['email', 'max:255', new InvitedBidderEmail()],
            'invites_sbo_ids' => ['nullable', 'array', 'max:50'],
            'invites_sbo_ids.*' => ['integer', 'exists:sbos,id'],
        ]);

        if (filled($data['inquiry_responses_at'] ?? null) && filled($data['order_deadline'] ?? null)
            && $data['inquiry_responses_at'] > $data['order_deadline']) {
            throw ValidationException::withMessages([
                'order_deadline' => 'Proposals cannot be due before responses to inquiries are sent.',
            ]);
        }

        $data['description'] = RichText::clean($data['description']);
        $data['terms'] = RichText::clean($data['terms'] ?? null) ?: null;

        if (RichText::isEmpty($data['description'])) {
            throw ValidationException::withMessages([
                'description' => 'Describe what you need vendors to quote for.',
            ]);
        }

        $procurement->fill(Arr::except($data, ['category', 'document', 'documents', 'featured_image', 'invites_emails', 'invites_sbo_ids']));
        $procurement->category_id = Category::where('name', $data['category'])->value('id');

        // Saving a draft through the full form publishes it: it has passed the
        // create rules, so it is complete enough to go live. An already-open
        // request stays open.
        if ('draft' === $procurement->getOriginal('status')) {
            $procurement->status = 'open';
        }

        if ($request->hasFile('document')) {
            if (filled($procurement->getOriginal('document_path'))) {
                Storage::disk('public')->delete((string) $procurement->getOriginal('document_path'));
            }

            $procurement->document_path = $request->file('document')->store('procurement', 'public');
        }

        $procurement->save();

        if ($request->hasFile('featured_image')) {
            $procurement->clearMediaCollection(PurchaseOrder::FEATURED_COLLECTION);
            $procurement->addMediaFromRequest('featured_image')
                ->toMediaCollection(PurchaseOrder::FEATURED_COLLECTION);
        }

        foreach ((array) $request->file('documents') as $file) {
            PurchaseOrderDocument::create([
                'purchase_order_id' => $procurement->id,
                'section' => PurchaseOrderDocument::SECTION_PROJECT,
                'name' => $file->getClientOriginalName(),
                'path' => $file->store('procurement-documents', 'public'),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        $this->syncInvites($procurement, $data['invites_emails'] ?? [], $data['invites_sbo_ids'] ?? []);

        return redirect()->route('procurement.show', $procurement)->with('status', 'rfq-updated');
    }

    public function bid(Request $request, PurchaseOrder $procurement): RedirectResponse
    {
        $sboIds = $request->user()->sbos()->pluck('id');

        abort_if($sboIds->contains($procurement->sbo_id), 403, 'You cannot bid on your own request.');
        abort_unless('open' === $procurement->status, 422, 'This request is no longer open.');

        /*
         * Verified businesses only.
         *
         * A bid is a commercial offer a buyer may act on, so the bidder has to
         * have been through verification — approved, documents compliant, fee
         * settled. Paying alone was enough before, which meant a brand new
         * account could bid on work the day it registered.
         *
         * Scoped to the verified ones rather than all of this owner's, so an
         * owner with one verified and one unverified listing can still bid, but
         * only as the verified one.
         */
        $verifiedSboIds = collect(app(SboNetworkAccess::class)->verifiedSboIdsFor($request->user()));

        abort_if(
            $verifiedSboIds->isEmpty(),
            403,
            'Only a verified business can submit a bid.',
        );

        $data = $request->validate([
            'bidder_sbo_id' => ['required', Rule::in($verifiedSboIds)],
            'proposal' => ['required', 'string'],
            // Several documents may be attached; only office documents, no images.
            'attachments' => ['nullable', 'array', 'max:15'],
            'attachments.*' => ['file', 'mimes:pdf,doc,docx', 'max:10240'],
            /*
             * The wizard's own answers, kept structured. `proposal` remains the
             * human-readable document (it is what a buyer reads end to end);
             * `details` is the same information as fields, so the Interest tab
             * can render a submitted bid instead of a wall of text.
             */
            'details' => ['nullable', 'array'],
            'details.contact' => ['nullable', 'array'],
            'details.address' => ['nullable', 'array'],
            'details.company' => ['nullable', 'array'],
            'details.declaration' => ['nullable', 'array'],
        ]);

        // One bid per SBO per RFQ: re-submitting replaces the previous one
        // rather than stacking duplicates.
        $bid = PurchaseOrderBid::firstOrNew([
            'purchase_order_id' => $procurement->id,
            'bidder_sbo_id' => $data['bidder_sbo_id'],
        ]);

        $bid->user_id = $request->user()->id;
        $bid->proposal = $data['proposal'];
        $bid->details = $data['details'] ?? null;
        $bid->status = 'submitted';
        $bid->submitted_at = now();

        if ($request->hasFile('attachments')) {
            $stored = [];

            foreach ($request->file('attachments') as $file) {
                $stored[] = [
                    'path' => $file->store('bids', 'public'),
                    'name' => $file->getClientOriginalName(),
                ];
            }

            $bid->attachment_paths = $stored;
            // The first also fills the legacy single column, so anything still
            // reading attachment_path keeps working.
            $bid->attachment_path = $stored[0]['path'] ?? null;
        }

        $bid->save();

        return redirect()->route('procurement.show', $procurement)->with('status', 'bid-submitted');
    }

    /**
     * Ask a question about a request — the Q&A tab's "Ask Question" button,
     * which had no table, no route and no form behind it.
     *
     * Open to any verified member rather than to bidders only: the point of
     * Q&A is to decide whether to bid, so requiring a bid first inverts it.
     * The RFQ's own owner is excluded — they answer, they do not ask.
     */
    public function question(Request $request, PurchaseOrder $procurement): RedirectResponse
    {
        $sboIds = $request->user()->sbos()->pluck('id');

        abort_if($sboIds->contains($procurement->sbo_id), 403, 'You cannot ask a question on your own request.');

        // Same bar as bidding: the Q&A thread is published to every bidder, so
        // it is not a channel an unverified account gets to post into.
        abort_if(
            [] === app(SboNetworkAccess::class)->verifiedSboIdsFor($request->user()),
            403,
            'Only a verified business can ask a question.',
        );

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        PurchaseOrderQuestion::create([
            'purchase_order_id' => $procurement->id,
            'user_id' => $request->user()->id,
            'sbo_id' => $sboIds->first(),
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
        ]);

        return back()->with('status', 'question-asked');
    }

    /** The buyer's answer. Only the SBO that posted the request may give one. */
    public function answerQuestion(
        Request $request,
        PurchaseOrder $procurement,
        PurchaseOrderQuestion $question,
    ): RedirectResponse {
        abort_unless($request->user()->sbos()->pluck('id')->contains($procurement->sbo_id), 403);
        abort_if($question->purchase_order_id !== $procurement->id, 404);

        $data = $request->validate(['answer' => ['required', 'string', 'max:5000']]);

        $question->forceFill([
            'answer' => $data['answer'],
            'answered_by' => $request->user()->id,
            'answered_at' => now(),
        ])->save();

        return back()->with('status', 'question-answered');
    }

    public function award(Request $request, PurchaseOrder $procurement): RedirectResponse
    {
        $sboIds = $request->user()->sbos()->pluck('id');
        abort_unless($sboIds->contains($procurement->sbo_id), 403);

        $data = $request->validate([
            'bid_id' => ['required', Rule::exists(PurchaseOrderBid::class, 'id')
                ->where('purchase_order_id', $procurement->id)],
            'award_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $procurement->forceFill([
            'awarded_bid_id' => $data['bid_id'],
            'award_note' => $data['award_note'] ?? null,
            'status' => 'awarded',
            'completed_at' => now(),
        ])->save();

        PurchaseOrderBid::where('purchase_order_id', $procurement->id)
            ->update(['status' => 'rejected']);
        PurchaseOrderBid::whereKey($data['bid_id'])->update(['status' => 'accepted']);

        // Invite the winner to share a testimonial. Sent here rather than from a
        // model observer because the status changes above are mass updates,
        // which do not fire Eloquent events.
        $winningBid = PurchaseOrderBid::with('user')->find($data['bid_id']);
        $winningBid?->user?->notify(new SubmitTestimonialNotification($winningBid));

        return back()->with('status', 'rfq-awarded');
    }

    /**
     * The winning business leaves a testimonial about the engagement.
     *
     * Only the winner may, and only on a request that was awarded to them. The
     * `display_publicly` flag is their consent to have it shown on the home
     * carousel and the awarded-bids page; without it the testimonial is kept
     * but never displayed. Re-submitting updates the one they already left.
     */
    public function storeTestimonial(Request $request, PurchaseOrder $procurement): RedirectResponse
    {
        $bid = $procurement->awardedBid;

        abort_if(null === $bid, 404);

        $sboIds = $request->user()->sbos()->pluck('id');
        abort_unless(
            $sboIds->contains($bid->bidder_sbo_id),
            403,
            'Only the winning business can leave a testimonial here.',
        );

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'headline' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'display_publicly' => ['boolean'],
        ]);

        Testimonial::updateOrCreate(
            ['purchase_order_id' => $procurement->id, 'sbo_id' => $bid->bidder_sbo_id],
            [
                'bid_id' => $bid->id,
                'user_id' => $request->user()->id,
                'rating' => $data['rating'],
                'headline' => $data['headline'],
                'body' => $data['body'],
                'display_publicly' => (bool) ($data['display_publicly'] ?? false),
            ],
        );

        return back()->with('status', 'testimonial-saved');
    }

    /**
     * Share a private request with the emails and businesses chosen in the form.
     *
     * The form owns the email and business invites: they are cleared and
     * rewritten each save, so removing one in the form removes it here. Invites
     * minted by approving an access request (a user invite) are left untouched.
     * A request set back to public keeps no invite list at all.
     *
     * @param  array<int, string>  $emails
     * @param  array<int, int>  $sboIds
     */
    private function syncInvites(PurchaseOrder $order, array $emails, array $sboIds, bool $notify = true): void
    {
        $order->invites()
            ->where(fn ($q) => $q->whereNotNull('invited_email')->orWhereNotNull('invited_sbo_id'))
            ->get()
            ->each(fn (PurchaseOrderInvite $invite) => $invite->delete());

        if ( ! $order->isInviteOnly()) {
            return;
        }

        foreach (array_unique(array_filter(array_map('mb_strtolower', $emails))) as $email) {
            $order->invites()->create([
                'invited_email' => $email,
                'invited_by_sbo_id' => $order->sbo_id,
                'status' => PurchaseOrderInvite::STATUS_PENDING,
            ]);

            // Not while it is a draft — nobody is told they are invited to a
            // request that has not been posted yet.
            if ( ! $notify) {
                continue;
            }

            // A member on this address hears about it in the app; a stranger is
            // emailed the link and can see it once they register with it.
            if ($member = User::where('email', $email)->first()) {
                $member->notify(new ProcurementInvitedNotification($order));
            } else {
                LaravelNotification::route('mail', $email)->notify(new ProcurementInvitedNotification($order));
            }
        }

        foreach (array_unique($sboIds) as $sboId) {
            $order->invites()->create([
                'invited_sbo_id' => $sboId,
                'invited_by_sbo_id' => $order->sbo_id,
                'status' => PurchaseOrderInvite::STATUS_PENDING,
            ]);

            if ($notify) {
                Sbo::find($sboId)?->creator?->notify(new ProcurementInvitedNotification($order));
            }
        }
    }
}
