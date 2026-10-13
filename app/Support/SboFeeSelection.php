<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use Illuminate\Validation\ValidationException;

/**
 * Applies a share-structure selection to an SBO: checks it against the
 * country's fee schedule, computes the fee SERVER-SIDE (a client-supplied
 * amount is never trusted) and persists it.
 *
 * Shared by the fee step (SboFeeController@confirm) and the add-business form,
 * which carries the applicant's selection so the fee is already confirmed by
 * the time the payment methods appear — no second calculator to fill in.
 *
 * Callers are expected to have validated that the required fields are present;
 * this enforces the rules the schedule itself imposes.
 */
final class SboFeeSelection
{
    /**
     * @param  array<string, mixed>  $data
     * @return int the computed fee
     */
    public static function apply(Sbo $sbo, array $data): int
    {
        $structure = (string) ($data['share_structure'] ?? '');

        if ( ! SboFeeCalculator::isValidStructure($structure)) {
            throw ValidationException::withMessages([
                'share_structure' => 'Please choose a valid share structure.',
            ]);
        }

        $countryId = $sbo->country_id;
        $isShare = SboFeeCalculator::isShareStructure($structure);
        $issuedShares = null;
        $bandLabel = null;

        try {
            if ($isShare) {
                $capitalType = (string) ($data['share_capital_type'] ?? '');

                if ( ! SboFeeCalculator::isValidCapitalType($structure, $capitalType, $countryId)) {
                    throw ValidationException::withMessages([
                        'share_capital_type' => 'Please choose a valid share capital type.',
                    ]);
                }

                $band = SboFeeCalculator::bandOptionFor($structure, (int) ($data['fee_band_id'] ?? 0), $countryId);

                if (null === $band) {
                    throw ValidationException::withMessages([
                        'fee_band_id' => 'Please choose a valid fee band.',
                    ]);
                }

                $perShare = (float) ($data['per_share_value'] ?? 0);
                $issuedShares = SboFeeCalculator::issuedSharesForBand($band, $perShare);

                if (null === $issuedShares) {
                    throw ValidationException::withMessages([
                        'fee_band_id' => 'The selected fee band cannot be used with this per-share value. Choose another band, or lower the per-share value.',
                    ]);
                }

                $fee = SboFeeCalculator::fee($structure, $issuedShares, $perShare, $countryId);
                $bandLabel = $band['label'];
            } else {
                $fee = SboFeeCalculator::fee($structure, null, null, $countryId);
            }
        } catch (SboFeeScheduleMisconfiguredException) {
            // Not the applicant's fault and not fixable by retrying: the
            // schedule itself has no band for this selection.
            throw ValidationException::withMessages([
                'share_structure' => 'The registration fee schedule is not configured for this selection. Please contact support.',
            ]);
        }

        $sbo->forceFill([
            'share_structure' => $structure,
            'share_capital_type' => $isShare ? $data['share_capital_type'] : null,
            'issued_shares_volume' => $issuedShares,
            'per_share_value' => $isShare ? $data['per_share_value'] : null,
            'calculated_fee' => $fee,
            'fee_band' => $bandLabel,
        ])->save();

        return $fee;
    }
}
