<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\ComplianceDocumentType;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\SboPaymentProof;
use App\Models\SupportEnquiry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * The screens whose forms collected but could not submit.
 *
 * Every endpoint here already existed and was already tested in isolation;
 * what these cover is that the payload each screen now sends is one the
 * endpoint accepts — the failure mode that a controller test cannot see.
 */

function formsUser(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function formsSbo(User $owner, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Forms SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
        'country' => 'Nigeria',
    ], $overrides));
}

/* ------------------------------------------------------ support enquiry --- */

test('the support form posts the payload the endpoint validates', function (): void {
    $user = formsUser();

    $this->actingAs($user)
        ->from(route('support.index'))
        ->post(route('support.enquiries.store'), [
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'category' => 'Billing',
            'sbo_number' => 'MBN-2026-0001',
            // The screen folds the category into the subject; there is no
            // category column on support_enquiries.
            'subject' => '[Billing] Invoice query',
            'message' => 'The registration fee was charged twice.',
        ])
        ->assertRedirect(route('support.index'));

    $enquiry = SupportEnquiry::first();

    expect($enquiry)->not->toBeNull()
        ->and($enquiry->subject)->toBe('[Billing] Invoice query')
        ->and($enquiry->user_id)->toBe($user->id)
        ->and($enquiry->status)->toBe('open');
});

test('the support form works signed out', function (): void {
    $this->from(route('support.index'))
        ->post(route('support.enquiries.store'), [
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'subject' => 'Getting started',
            'message' => 'How do I register?',
        ])
        ->assertRedirect(route('support.index'));

    expect(SupportEnquiry::first()->user_id)->toBeNull();
});

/* -------------------------------------------------- manual payment proof --- */

/*
 * A receipt with real PDF bytes in it.
 *
 * `UploadedFile::fake()->create('receipt.pdf', 64, 'application/pdf')` writes a
 * file of null bytes, so its true type reads as application/x-empty — and the
 * payment_proof collection checks the CONTENT, not the name. The fake passed
 * validation and was then refused by the media library.
 */
function fakeReceiptPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'receipt.pdf',
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
    );
}

test('the manual payment panel uploads a proof and flags the fee as submitted', function (): void {
    Storage::fake('compliance');
    Storage::fake('public');

    $user = formsUser();
    $sbo = formsSbo($user, ['payment_status' => 'unpaid', 'payment_confirmed_at' => null]);

    $this->actingAs($user)
        ->from(route('sbo.payment-required'))
        ->post(route('sbo.payments.proof.store', $sbo), [
            'proof' => fakeReceiptPdf(),
        ])
        // The proof is submitted; the owner stays put awaiting admin approval
        // (documents open only once the payment is approved).
        ->assertRedirect(route('sbo.payment-required'));

    expect(SboPaymentProof::where('sbo_id', $sbo->id)->where('status', 'pending')->exists())
        ->toBeTrue()
        ->and($sbo->fresh()->payment_submitted_at)->not->toBeNull();
});

test('a proof cannot be uploaded against someone else\'s sbo', function (): void {
    $sbo = formsSbo(formsUser());

    $this->actingAs(formsUser())
        ->post(route('sbo.payments.proof.store', $sbo), [
            'proof' => fakeReceiptPdf(),
        ])
        ->assertForbidden();
});

/* ----------------------------------------------------- compliance upload --- */

test('the application documents step lists what is required and accepts an upload', function (): void {
    Storage::fake('compliance');

    $user = formsUser();
    // Step 3 requires the fee to be settled first, so this applicant has paid.
    $sbo = formsSbo($user, ['payment_status' => 'paid', 'payment_confirmed_at' => now()]);

    $type = ComplianceDocumentType::create([
        'name' => 'Certificate of Incorporation',
        'description' => 'Issued by the registrar.',
        'is_required' => true,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('application.show', ['step' => 'documents']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/ApplicationView')
            ->where('documents.0.name', 'Certificate of Incorporation')
            ->where('documents.0.required', true)
            ->where('documents.0.status', null));

    $this->actingAs($user)
        ->from(route('application.show', ['step' => 'documents']))
        ->post(route('sbo.documents.store'), [
            'sbo_id' => $sbo->id,
            'compliance_document_type_id' => $type->id,
            /*
             * A real image, not `fake()->create()`, which produces a zero-byte
             * file with mime application/x-empty. That passed only while the
             * upload was landing in an unregistered media collection with no
             * mime guard — which was also why the file was then invisible.
             */
            'document' => UploadedFile::fake()->image('coi.png', 800, 600),
        ])
        ->assertRedirect(route('application.show', ['step' => 'documents']));

    expect(SboComplianceDocument::where('sbo_id', $sbo->id)
        ->where('compliance_document_type_id', $type->id)
        ->where('status', 'pending')
        ->exists())->toBeTrue();

    // And the screen now reports it back as in review.
    $this->actingAs($user)
        ->get(route('application.show', ['step' => 'documents']))
        ->assertInertia(fn ($page) => $page->where('documents.0.status', 'pending'));
});

/* -------------------------------------------------------------- RFQ post --- */

test('the post-procurement form creates a real request', function (): void {
    Storage::fake('public');

    $user = formsUser();
    $sbo = formsSbo($user);
    $category = Category::firstOrCreate(['name' => 'Technology Services'], ['slug' => 'technology-services']);

    $this->actingAs($user)
        ->get(route('procurement.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('procurement/ProcurementCreateView')
            ->where('sbos.0.id', $sbo->id)
            ->where('summary.total', 0));

    $this->actingAs($user)
        ->post(route('procurement.store'), [
            'sbo_id' => $sbo->id,
            'title' => 'Supply and install media equipment',
            'description' => 'Two 4K displays and mounting.',
            'category' => 'Technology Services',
            'request_type' => 'product',
            'budget' => '500000',
            // A number with no currency is not a budget on a registry that
            // spans several countries; the form always sends one.
            'budget_currency' => 'NGN',
            'quantity' => '200',
            'delivery_location' => 'Lagos Zone 1',
            'order_deadline' => now()->addMonth()->toDateString(),
            'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
            'terms' => 'Net 30.',
            'document' => UploadedFile::fake()->create('spec.pdf', 64, 'application/pdf'),
        ])
        ->assertRedirect();

    $order = PurchaseOrder::first();

    expect($order)->not->toBeNull()
        ->and($order->budget_currency)->toBe('NGN')
        ->and($order->sbo_id)->toBe($sbo->id)
        ->and($order->category_id)->toBe($category->id)
        ->and($order->status)->toBe('open')
        ->and($order->document_path)->not->toBeNull();
});

/* ------------------------------------------------------------- bid wizard -- */

test('the bid wizard posts one bid composed from every step', function (): void {
    Storage::fake('public');

    $buyer = formsUser();
    $buyerSbo = formsSbo($buyer, ['name' => 'Buyer Ltd']);
    $category = Category::firstOrCreate(['name' => 'Technology Services'], ['slug' => 'technology-services']);

    $order = PurchaseOrder::create([
        'sbo_id' => $buyerSbo->id,
        'category_id' => $category->id,
        'title' => 'Supply media equipment',
        'description' => 'Spec attached.',
        'request_type' => 'product',
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ]);

    $bidder = formsUser();
    $bidderSbo = formsSbo($bidder, ['name' => 'Bidder Ltd']);

    $this->actingAs($bidder)
        ->get(route('procurement.bid', ['procurement' => $order->id, 'step' => '1']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('procurement/BidWizardView')
            ->where('ownRequest', false)
            ->where('closed', false)
            ->where('existingBid', null)
            ->where('sbos.0.id', $bidderSbo->id));

    $this->actingAs($bidder)
        ->post(route('procurement.bid.store', $order), [
            'bidder_sbo_id' => $bidderSbo->id,
            'proposal' => "## Contact\nPrimary: Ada Obi (Director)\n\n## Declaration\nSigned by: Ada Obi",
            'attachments' => [UploadedFile::fake()->create('technical.pdf', 64, 'application/pdf')],
        ])
        ->assertRedirect(route('procurement.show', $order));

    $bid = PurchaseOrderBid::first();

    expect($bid)->not->toBeNull()
        ->and($bid->bidder_sbo_id)->toBe($bidderSbo->id)
        ->and($bid->status)->toBe('submitted')
        ->and($bid->proposal)->toContain('Signed by: Ada Obi')
        ->and($bid->attachment_path)->not->toBeNull()
        ->and($bid->attachment_paths)->toHaveCount(1);

    // The wizard reports the existing bid back, so the owner knows a second
    // submission replaces it.
    $this->actingAs($bidder)
        ->get(route('procurement.bid', ['procurement' => $order->id, 'step' => '1']))
        ->assertInertia(fn ($page) => $page->where('existingBid.status', 'submitted'));
});

test('the wizard tells an owner up front that they cannot bid on their own request', function (): void {
    $buyer = formsUser();
    $buyerSbo = formsSbo($buyer);
    $category = Category::firstOrCreate(['name' => 'Technology Services'], ['slug' => 'technology-services']);

    $order = PurchaseOrder::create([
        'sbo_id' => $buyerSbo->id,
        'category_id' => $category->id,
        'title' => 'Own request',
        'description' => '.',
        'request_type' => 'product',
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ]);

    $this->actingAs($buyer)
        ->get(route('procurement.bid', ['procurement' => $order->id, 'step' => '1']))
        ->assertInertia(fn ($page) => $page->where('ownRequest', true));
});

/* ------------------------------------------------------------------ award -- */

test('the award panel is owner-only and awards a bid', function (): void {
    $buyer = formsUser();
    $buyerSbo = formsSbo($buyer);
    $category = Category::firstOrCreate(['name' => 'Technology Services'], ['slug' => 'technology-services']);

    $order = PurchaseOrder::create([
        'sbo_id' => $buyerSbo->id,
        'category_id' => $category->id,
        'title' => 'Supply media equipment',
        'description' => '.',
        'request_type' => 'product',
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ]);

    $bidder = formsUser();
    $bidderSbo = formsSbo($bidder, ['name' => 'Bidder Ltd']);
    $loser = formsUser();
    $loserSbo = formsSbo($loser, ['name' => 'Runner Up Ltd']);

    $winning = PurchaseOrderBid::create([
        'purchase_order_id' => $order->id,
        'user_id' => $bidder->id,
        'bidder_sbo_id' => $bidderSbo->id,
        'proposal' => 'We can do it.',
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);
    $losing = PurchaseOrderBid::create([
        'purchase_order_id' => $order->id,
        'user_id' => $loser->id,
        'bidder_sbo_id' => $loserSbo->id,
        'proposal' => 'So can we.',
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);

    // A bidder sees the request but not the other bids.
    $this->actingAs($bidder)
        ->get(route('procurement.show', ['procurement' => $order->id]))
        ->assertInertia(fn ($page) => $page->where('isOwner', false)->where('bids', []));

    $this->actingAs($buyer)
        ->get(route('procurement.show', ['procurement' => $order->id]))
        ->assertInertia(fn ($page) => $page
            ->where('isOwner', true)
            ->where('awardedBidId', null)
            ->count('bids', 2));

    $this->actingAs($buyer)
        ->from(route('procurement.show', ['procurement' => $order->id]))
        ->post(route('procurement.award', $order), [
            'bid_id' => $winning->id,
            'award_note' => 'Best value.',
        ])
        ->assertRedirect(route('procurement.show', ['procurement' => $order->id]));

    expect($order->fresh()->awarded_bid_id)->toBe($winning->id)
        ->and($order->fresh()->status)->toBe('awarded')
        ->and($winning->fresh()->status)->toBe('accepted')
        ->and($losing->fresh()->status)->toBe('rejected');
});

test('a bidder cannot award a request they did not post', function (): void {
    $buyer = formsUser();
    $buyerSbo = formsSbo($buyer);
    $category = Category::firstOrCreate(['name' => 'Technology Services'], ['slug' => 'technology-services']);

    $order = PurchaseOrder::create([
        'sbo_id' => $buyerSbo->id,
        'category_id' => $category->id,
        'title' => 'Supply media equipment',
        'description' => '.',
        'request_type' => 'product',
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ]);

    $bidder = formsUser();
    $bidderSbo = formsSbo($bidder);
    $bid = PurchaseOrderBid::create([
        'purchase_order_id' => $order->id,
        'user_id' => $bidder->id,
        'bidder_sbo_id' => $bidderSbo->id,
        'proposal' => 'Pick me.',
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);

    $this->actingAs($bidder)
        ->post(route('procurement.award', $order), ['bid_id' => $bid->id])
        ->assertForbidden();

    expect($order->fresh()->awarded_bid_id)->toBeNull();
});

/* -------------------------------------------------------------- auth forms -- */

test('the forgot-password form posts to a real endpoint', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('status');
});

test('the reset form posts the token and the address it was given', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $token = Password::createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/ResetPasswordView')
            ->where('token', $token)
            ->where('email', $user->email));

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'N3w-Str0ng-Pass!',
        'password_confirmation' => 'N3w-Str0ng-Pass!',
    ])->assertRedirect(route('login'));

    expect(Hash::check('N3w-Str0ng-Pass!', $user->fresh()->password))->toBeTrue();
});

test('the verify-email screen knows the address and can resend', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/VerifyEmailView')
            ->where('email', $user->email));

    $this->actingAs($user)
        ->from(route('verification.notice'))
        ->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'verification-link-sent');
});
