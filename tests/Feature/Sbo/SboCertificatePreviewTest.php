<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Reading the certificate without keeping it.
 *
 * The seal panel offered a download and nothing else, which answers "can I
 * have a copy" rather than "what does it look like" — it put a file on the
 * member's disk before they knew whether they wanted one, and on a phone that
 * often means it disappears into a downloads folder unopened.
 *
 * The preview is the same document served inline. It is deliberately the SAME
 * document: a preview built separately would drift from the thing it claims to
 * preview, and a member would download something they had not seen.
 */

/*
 * Named apart from SboSealAndCertificateTest's certificateOwner(): Pest loads
 * every file in one process, so two helpers with one name is a fatal redeclare
 * rather than a shadowed function.
 */
function previewOwner(): User
{
    return paidMember([], ['name' => 'Certificate Co']);
}

test('the panel offers a preview alongside the download', function (): void {
    $owner = previewOwner();
    $sbo = $owner->sbos()->first();

    $this->actingAs($owner)
        ->get(route('sbo.applications.progress', $sbo))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('seal.certificateUrl', route('sbo.certificate.download', $sbo))
            ->where('seal.certificatePreviewUrl', route('sbo.certificate.preview', $sbo)));
});

test('both seal panels offer it, not just one', function (): void {
    /*
     * The two seal cards deliberately do not share their layout — one is a
     * narrow sidebar, the other a full-width card — and the first version of
     * this feature went into the sidebar only. So the page most members
     * actually visit offered a download and nothing else.
     *
     * Checked in the templates because that is where it went wrong: the
     * payload carried the URL to both screens the whole time.
     */
    $views = [
        'resources/js/spa/components/sbo/SboSealPanel.vue',
        'resources/js/spa/views/account/ProfileDashboardView.vue',
    ];

    // toContain() takes further arguments as MORE needles, not as a failure
    // message — passing one asserts the file contains the message too.
    foreach ($views as $view) {
        expect(file_get_contents(base_path($view)))->toContain('SboCertificatePreview');
    }
});

test('the profile dashboard is handed the preview URL too', function (): void {
    $owner = previewOwner();
    $sbo = $owner->sbos()->first();

    $this->actingAs($owner)
        ->get(route('account.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('seal.certificatePreviewUrl', route('sbo.certificate.preview', $sbo)));
});

test('the preview renders inline and the download does not', function (): void {
    $owner = previewOwner();
    $sbo = $owner->sbos()->first();

    $preview = $this->actingAs($owner)
        ->get(route('sbo.certificate.preview', $sbo))
        ->assertOk();

    // `inline` is the whole difference: it is what makes the browser's own
    // viewer open the file instead of saving it.
    expect($preview->headers->get('content-type'))->toContain('application/pdf')
        ->and($preview->headers->get('content-disposition'))->toStartWith('inline');

    $download = $this->actingAs($owner)
        ->get(route('sbo.certificate.download', $sbo))
        ->assertOk();

    expect($download->headers->get('content-disposition'))->toStartWith('attachment');
});

test('both routes render the same document', function (): void {
    $owner = previewOwner();
    $sbo = $owner->sbos()->first();

    $preview = $this->actingAs($owner)->get(route('sbo.certificate.preview', $sbo));
    $download = $this->actingAs($owner)->get(route('sbo.certificate.download', $sbo));

    /*
     * A PDF embeds a creation timestamp, so the bytes differ between two
     * renders of the same content. Size is the closest honest proxy: a preview
     * built from a different view would not land within a few bytes of the
     * document it claims to preview.
     */
    expect(abs(mb_strlen($preview->getContent()) - mb_strlen($download->getContent())))
        ->toBeLessThan(2048);
})->skip(fn (): bool => ! extension_loaded('gd'), 'The seal is drawn with GD.');

test('someone else cannot preview a certificate that is not theirs', function (): void {
    $sbo = previewOwner()->sbos()->first();

    $this->actingAs(paidMember())
        ->get(route('sbo.certificate.preview', $sbo))
        ->assertForbidden();
});

test('a guest cannot preview one at all', function (): void {
    $sbo = previewOwner()->sbos()->first();

    $this->get(route('sbo.certificate.preview', $sbo))
        ->assertRedirect(route('login'));
});

test('an unpaid listing has no certificate to preview', function (): void {
    // Issuance begins at payment, so there is nothing to show before it.
    $owner = User::factory()->create([
        'role' => App\Enums\UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    $sbo = Sbo::create([
        'name' => 'Unpaid Co',
        'slug' => 'unpaid-co-' . Illuminate\Support\Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'payment_status' => 'unpaid',
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('sbo.certificate.preview', $sbo))
        ->assertNotFound();
});
