<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The Digital SBO Seal panel: the badge image, the certificate, and what is
 * still outstanding when neither exists yet.
 *
 * Extracted from DashboardController so the application-progress screen can
 * show the same thing. That screen drew its own card — a static ribbon glyph,
 * the words "LBN Certified Member 2026", a "VETTED" pill and a Download button
 * with no handler behind it — so a member whose seal had genuinely been issued
 * saw a fake one, and could not download the certificate that actually existed.
 */
final class SboSealPanel
{
    /**
     * @param  Collection<int, Sbo>  $sbos
     * @return array{issued: bool, status: string|null, sboName: string|null, checklist: list<array{label: string, done: bool}>, imageUrl: string|null, certificateUrl: string|null, certificatePreviewUrl: string|null, number: string|null, issuedOn: string|null}
     */
    public static function for(Collection $sbos): array
    {
        $withSeals = self::issuedSealsFor($sbos);
        $sbo = $withSeals->first(fn (Sbo $s): bool => null !== $s->seal);

        /*
         * No seal yet: report the nearest application so the checklist shows
         * that business's real progress rather than four grey ticks.
         */
        if ( ! $sbo instanceof Sbo) {
            $pending = $sbos->first();

            return [
                'issued' => false,
                'status' => null,
                'sboName' => $pending?->name,
                'checklist' => $pending instanceof Sbo ? self::checklist($pending) : [],
                'imageUrl' => null,
                'certificateUrl' => null,
                'certificatePreviewUrl' => null,
                'number' => null,
                'issuedOn' => null,
            ];
        }

        $seal = $sbo->seal;

        return [
            'issued' => true,
            'status' => $seal->status,
            'sboName' => $sbo->name,
            'checklist' => self::checklist($sbo),
            'imageUrl' => $seal->existsOnDisk() ? ($seal->previewDataUri() ?? $seal->url()) : null,
            'certificateUrl' => route('sbo.certificate.download', $sbo),
            'certificatePreviewUrl' => route('sbo.certificate.preview', $sbo),
            'number' => $seal->issued_number,
            'issuedOn' => $seal->issued_at?->format('F j, Y'),
        ];
    }

    /** Convenience for the screens that are already scoped to one listing. */
    public static function forSbo(?Sbo $sbo): array
    {
        return self::for($sbo instanceof Sbo ? collect([$sbo]) : collect());
    }

    /**
     * @return list<array{label: string, done: bool}>
     */
    private static function checklist(Sbo $sbo): array
    {
        return [
            ['label' => __('seal.registered'), 'done' => true],
            ['label' => __('seal.fees_paid'), 'done' => $sbo->feesPaid()],
            ['label' => __('seal.documents'), 'done' => 'compliant' === $sbo->documents_status],
            ['label' => __('seal.approved'), 'done' => 'approved' === $sbo->verification_status],
        ];
    }

    /**
     * @param  Collection<int, Sbo>  $sbos
     * @return Collection<int, Sbo>
     */
    private static function issuedSealsFor(Collection $sbos): Collection
    {
        return $sbos
            /*
             * Paid, not necessarily verified: a provisional seal is issued at
             * payment and upgraded later. Filtering on isFullyVerified() here
             * meant a paid member never saw one.
             */
            ->filter(fn (Sbo $sbo): bool => $sbo->feesPaid())
            ->map(function (Sbo $sbo): Sbo {
                // Re-issue when the file is missing OR when verification has
                // moved on since the badge was drawn.
                $stale = $sbo->seal
                    && $sbo->isFullyVerified()
                    && ! $sbo->seal->isVerified();

                if ( ! $sbo->seal?->existsOnDisk() || $stale) {
                    try {
                        $seal = app(SboSealIssuer::class)->issueFor($sbo);
                        $sbo->setRelation('seal', $seal);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }

                return $sbo;
            });
    }
}
