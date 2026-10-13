<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use App\Models\User;

/**
 * Everything the two fee screens need to draw the calculator and both payment
 * methods, in one shape.
 *
 * `/application/fees` and `/sbo/complete-payment-required` are the same step of
 * the same flow drawn twice (Figma 33:4 and 384:17211), so they read the same
 * payload rather than each assembling its own and drifting.
 *
 * The screens previously hardcoded "50 Espees" and three invented share
 * structures. Both come from the fee schedule now, which is country-scoped:
 * a Nigerian applicant and a UK one are quoted different amounts from
 * different bands.
 */
final class SboFeePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?Sbo $sbo, ?User $user): array
    {
        if (null === $sbo) {
            return ['sboId' => null, 'confirmed' => false, 'structures' => [], 'espees' => self::espees(null, null)];
        }

        $countryId = $sbo->country_id;

        return [
            'sboId' => $sbo->id,
            'sboName' => $sbo->name,
            'currency' => SboFeeCalculator::currency($countryId),

            'structures' => self::structuresFor($countryId),

            // What the owner has already chosen, so a revisit is not a reset.
            'selection' => [
                'shareStructure' => $sbo->share_structure ?: null,
                'shareCapitalType' => $sbo->share_capital_type,
                'perShareValue' => $sbo->per_share_value,
                'issuedShares' => $sbo->issued_shares_volume,
            ],

            'flatFee' => SboFeeCalculator::flatFee($countryId),
            'calculatedFee' => $sbo->calculated_fee,
            'feeBand' => $sbo->fee_band,

            /*
             * `confirmed` gates both payment methods — it is exactly the
             * condition SboEspeesPaymentController@start checks before it will
             * open a checkout.
             */
            'confirmed' => null !== $sbo->calculated_fee || EspeesTestAccount::matches($user),
            'paid' => $sbo->feesPaid(),
            'proofSubmitted' => $sbo->hasSubmittedPayment(),

            /*
             * The manual-payment instructions: which merchant to pay, and in
             * which app.
             *
             * These were `const MERCHANT_CODE = 'MOCDII1'` and the literal
             * "Espees Max" in PaymentMethods.vue, so the screen told every
             * applicant to pay a merchant code that had nothing to do with the
             * one configured on the Fees admin page — an administrator could
             * set ESPMER01 there and applicants would still be sent to
             * MOCDII1. Country-scoped like every other fee setting.
             */
            'merchant' => SboFeeCalculator::merchant($countryId),

            'espees' => self::espees($countryId, $user),
        ];
    }

    /**
     * The same fee schedule, priced for a COUNTRY before any business record
     * exists — the inline calculator on the business-registration form reads
     * this as the applicant picks their country, so they see the fee while
     * signing up. It carries no application state (no selection, nothing paid).
     *
     * @return array<string, mixed>
     */
    public static function forCountry(?int $countryId, ?User $user): array
    {
        return [
            'sboId' => null,
            'currency' => SboFeeCalculator::currency($countryId),
            'structures' => self::structuresFor($countryId),
            'selection' => [
                'shareStructure' => null,
                'shareCapitalType' => null,
                'perShareValue' => null,
                'issuedShares' => null,
            ],
            'flatFee' => SboFeeCalculator::flatFee($countryId),
            'calculatedFee' => null,
            'feeBand' => null,
            // A preview only: nothing is confirmed or paid yet.
            'confirmed' => false,
            'paid' => false,
            'proofSubmitted' => false,
            'merchant' => SboFeeCalculator::merchant($countryId),
            /*
             * Real gateway state for the country, not a blank shape: the
             * add-business form takes the payment itself now, so its Espees
             * button has to know whether checkout can run before a business
             * exists to run it against.
             */
            'espees' => self::espees($countryId, $user),
        ];
    }

    /**
     * Every structure for a country, each carrying its own capital types and
     * bands.
     *
     * Sent whole rather than for the saved structure alone: changing the
     * structure changes which bands apply, and fetching them per change would
     * mean a round trip on a select that has four options. The schedule is
     * small and identical for every applicant in a country, so it costs less to
     * send it all once.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function structuresFor(?int $countryId): array
    {
        return collect(SboFeeCalculator::structures())
            ->map(function (string $label, string $key) use ($countryId): array {
                $share = SboFeeCalculator::isShareStructure($key);

                return [
                    'key' => $key,
                    'label' => $label,
                    // Tells the screen whether to open the three share-capital
                    // inputs or go straight to the flat fee.
                    'isShare' => $share,
                    'capitalTypes' => $share
                        ? collect(SboFeeCalculator::capitalTypesFor($key, $countryId))
                            ->map(fn (string $l, string $k): array => ['key' => $k, 'label' => $l])
                            ->values()
                            ->all()
                        : [],
                    'bands' => $share
                        ? array_map(fn (array $band): array => [
                            'id' => $band['id'],
                            'label' => $band['display_label'],
                            'fee' => $band['fee'],
                            // The screen needs this to warn before posting that
                            // a per-share value overshoots the band.
                            'maxEquity' => $band['max_equity'],
                            'previousMaxEquity' => $band['previous_max_equity'],
                        ], SboFeeCalculator::bandOptionsFor($key, $countryId))
                        : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Whether Espees checkout can actually run, and why not when it cannot.
     *
     * Without this the button posted, the controller redirected back with a
     * flash, and the screen showed nothing — so an unconfigured gateway looked
     * like a broken button. The gateway's token and wallet are deliberately
     * NOT exposed; only whether they are set.
     *
     * @return array<string, mixed>
     */
    private static function espees(?int $countryId, ?User $user): array
    {
        $gateway = SboFeeCalculator::espeesGateway($countryId);
        $isTestAccount = EspeesTestAccount::matches($user);
        $mayRun = $isTestAccount
            ? EspeesTestAccount::gatewayMayRun($gateway)
            : $gateway['configured'];

        return [
            'available' => $mayRun,
            'testMode' => (bool) $gateway['test_mode'],
            // The dedicated test account pays a fixed 1 Espee and skips the
            // fee calculation entirely.
            'testAccount' => $isTestAccount,
            'reason' => $mayRun ? null : self::unavailableReason($gateway),
        ];
    }

    /**
     * Why Espees cannot run, naming the setting that is actually missing.
     *
     * This used to be one fixed sentence blaming the token and wallet, which
     * sent administrators to re-enter credentials that were already correct.
     * The far more common cause is `espees_enabled` still being off: the Fees
     * page saves the token and wallet independently of the toggle, so filling
     * both in and never flipping "Enable Espees checkout" leaves a gateway that
     * is fully credentialled and still refuses to run.
     */
    private static function unavailableReason(array $gateway): string
    {
        $missing = [];

        if ('' === $gateway['token']) {
            $missing[] = 'merchant token';
        }

        if ('' === $gateway['merchant_wallet']) {
            $missing[] = 'merchant wallet';
        }

        $tail = ' You can still pay manually below.';

        if ([] !== $missing) {
            return 'Espees checkout is missing its ' . implode(' and ', $missing)
                . '. An administrator sets these on the Fees admin page.' . $tail;
        }

        if ( ! $gateway['enabled']) {
            return 'Espees checkout is switched off for this country. The credentials are in place — '
                . 'an administrator turns on "Enable Espees checkout" on the Fees admin page.' . $tail;
        }

        return 'Espees checkout is not available for this country yet.' . $tail;
    }
}
