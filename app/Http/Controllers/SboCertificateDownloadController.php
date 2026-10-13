<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sbo;
use App\Models\SboSeal;
use App\Support\AdminCapabilities;
use App\Support\QrCodeImage;
use App\Support\SboSealIssuer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The downloadable certificate — the seal plus the business's own details.
 *
 * The seal PNG carries only the registration number stamped onto its plaque,
 * which is not a certificate: it says nothing about whose business it is. This
 * renders a document around it with the name, category, address, contacts and
 * the dates the registration actually reached each stage.
 *
 * Generated on request rather than stored: every field is already a column on
 * `sbos`, so a cached PDF could only ever be a stale copy of them.
 */
final class SboCertificateDownloadController extends Controller
{
    /** Save it to disk. */
    public function download(Request $request, Sbo $sbo): Response
    {
        [$pdf, $seal] = $this->certificate($request, $sbo);

        return $pdf->download($seal->certificateName());
    }

    /**
     * Read it without saving it.
     *
     * The same document, served inline so the browser's own PDF viewer opens
     * it. The panel offered downloading only, which is a poor way to answer
     * "what does my certificate look like" — it puts a file on the member's
     * disk before they know whether they want one, and on a phone it often
     * disappears into a downloads folder unopened.
     */
    public function preview(Request $request, Sbo $sbo): Response
    {
        [$pdf, $seal] = $this->certificate($request, $sbo);

        return $pdf->stream($seal->certificateName());
    }

    /** Trim to what fits on a ruled line, rather than over the next label. */
    private static function clamp(?string $value, int $length): string
    {
        $value = mb_trim((string) $value);

        return mb_strlen($value) > $length
            ? mb_substr($value, 0, $length - 1) . '…'
            : $value;
    }

    /**
     * @return array{0: \Barryvdh\DomPDF\PDF, 1: SboSeal}
     */
    private function certificate(Request $request, Sbo $sbo): array
    {
        $user = $request->user();
        abort_unless(null !== $user, 403);

        $isOwner = (int) $sbo->created_by === (int) $user->id;
        $isComplianceAdmin = $user->hasPermission(AdminCapabilities::APPROVE_SBOS);

        abort_unless($isOwner || $isComplianceAdmin, 403);

        // No fee, no certificate — issuance begins at payment.
        abort_unless($sbo->feesPaid(), 404);

        $seal = $sbo->seal()->first();

        /*
         * Issue on demand if the seal has not been drawn yet (payment
         * confirmed while GD was unavailable, or a listing paid before seals
         * were issued at payment at all).
         */
        if ( ! $seal instanceof SboSeal) {
            $seal = app(SboSealIssuer::class)->issueFor($sbo);
        }

        $verifyUrl = $sbo->moc_registration_number
            ? route('sbo.verify', $sbo->moc_registration_number)
            : route('businesses.show', $sbo->slug);

        $pdf = Pdf::loadView('pdf.sbo-certificate', [
            'sbo' => $sbo,
            'seal' => $seal,
            // The supplied artwork, embedded: dompdf will not fetch over HTTP,
            // and an absolute path breaks as soon as the app is not local.
            'template' => $this->templateDataUri(),
            'fields' => $this->fields($sbo, $seal),
            // A real code over the artwork's placeholder one.
            'qr' => QrCodeImage::dataUri($verifyUrl),
            'verifyUrl' => $verifyUrl,
        ])->setPaper('a4', 'landscape');

        return [$pdf, $seal];
    }

    /** The certificate artwork as a data URI. */
    private function templateDataUri(): ?string
    {
        $path = resource_path('images/certificate-template.jpg');

        if ( ! is_file($path)) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($path));
    }

    /**
     * The ruled fields the artwork leaves blank.
     *
     * Every one is a column on `sbos` or a date the registration actually
     * reached, so nothing on the sheet is decoration presented as a record. A
     * value with nothing behind it prints as an empty rule rather than a dash —
     * a blank line reads as "not yet", where "—" reads as "none".
     *
     * @return array<string, string>
     */
    private function fields(Sbo $sbo, SboSeal $seal): array
    {
        return [
            /*
             * Clamped: the rule between "Business Name:" and "Kingdom Business
             * ID:" is about 28 characters wide, and dompdf has no ellipsis, so
             * a long name would run straight through the next label. The
             * trading name is preferred where there is one — it is the shorter
             * of the two and the one a customer recognises.
             */
            'name' => self::clamp($sbo->trading_name ?: $sbo->name, 30),
            /*
             * Kingdom Business ID is the registry's own application id; the
             * Trading License Number is the seal's issued number. They are
             * different things and the sheet asks for both.
             */
            'businessId' => (string) ($sbo->application_id ?? ''),
            'licenseNumber' => (string) ($seal->issued_number ?? ''),
            'registryNumber' => (string) ($sbo->moc_registration_number ?? ''),
            // The sector, not the trade: "Technology", not "Software
            // Development". See App\Support\SboIndustries.
            'sector' => self::clamp((string) ($sbo->industry ?? $sbo->category ?? ''), 26),
            'city' => self::clamp((string) ($sbo->city ?? ''), 30),
            'state' => self::clamp((string) ($sbo->state ?? ''), 24),
            'country' => self::clamp((string) ($sbo->country ?? ''), 30),
            'registeredOn' => $sbo->date_received?->format('j M Y') ?? $sbo->created_at?->format('j M Y') ?? '',
            'issuedOn' => $seal->issued_at?->format('j M Y') ?? '',
            /*
             * A licence that never expires does not need renewing, and the
             * registry has no renewal cycle — so this is the anniversary of
             * issue, which is what a reader takes "renewal date" to mean.
             */
            'renewalOn' => $seal->issued_at?->addYear()->format('j M Y') ?? '',
            // Payment issues the seal; verification upgrades it. The licence
            // says which of the two states this business is in.
            'status' => $seal->isVerified() ? 'Verified' : 'Provisional',
            'membership' => $seal->isVerified() ? 'Verified Member' : 'Registered Member',
        ];
    }
}
