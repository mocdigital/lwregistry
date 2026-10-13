<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sbo;
use App\Support\SboFeeCalculator;
use App\Support\SboFeeSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Confirming the registration fee — step 2 of the application.
 *
 * This was the hole in the middle of the payment flow. Both payment methods
 * refuse to start until `sbos.calculated_fee` is set:
 * `SboEspeesPaymentController@start` bails with "Confirm the registration fee
 * before starting Espees checkout", and the Livewire proof upload aborts 400.
 * The designed screen has a "Registration Fee Calculator", but it posted
 * nowhere, so `calculated_fee` was always null and neither method could ever
 * run from the Vue side.
 *
 * Mirrors `App\Livewire\SboFees::confirmSelections()`, including the rule that
 * matters most: **the fee is computed here, never accepted from the client.**
 */
final class SboFeeController extends Controller
{
    public function confirm(Request $request, Sbo $sbo): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        $structure = (string) $request->input('share_structure', '');

        if ( ! SboFeeCalculator::isValidStructure($structure)) {
            throw ValidationException::withMessages([
                'share_structure' => 'Please choose a valid share structure.',
            ]);
        }

        $countryId = $sbo->country_id;
        $isShare = SboFeeCalculator::isShareStructure($structure);

        $rules = ['share_structure' => ['required', 'string']];

        if ($isShare) {
            $rules += [
                'share_capital_type' => ['required', 'string'],
                'fee_band_id' => ['required', 'integer'],
                'per_share_value' => ['required', 'numeric', 'min:0.01'],
            ];
        }

        $data = $request->validate($rules, [], [
            'share_capital_type' => 'share capital type',
            'fee_band_id' => 'fee band',
            'per_share_value' => 'per-share value',
        ]);

        // The schedule checks, the server-side computation and the save — shared
        // with the add-business form, which carries the same selection.
        $fee = SboFeeSelection::apply($sbo, $data);

        return back()->with(
            'fees_success',
            'Fee confirmed: ' . number_format($fee) . ' ' . SboFeeCalculator::currency($countryId)
                . '. You can now pay with Espees or upload proof of a manual transfer.',
        );
    }

    /**
     * "Edit selection" — clears the confirmed fee so the calculator reopens.
     * Refused once the fee is actually paid: re-opening it then would leave
     * the application claiming a fee different from the one settled.
     */
    public function reset(Request $request, Sbo $sbo): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        if ($sbo->feesPaid()) {
            return back()->with('fees_error', 'This fee has already been paid and cannot be changed.');
        }

        $sbo->forceFill(['calculated_fee' => null, 'fee_band' => null])->save();

        return back()->with('fees_success', 'Fee selection reopened.');
    }

    private function authorizeOwner(Request $request, Sbo $sbo): void
    {
        abort_unless((int) $sbo->created_by === (int) $request->user()?->id, 403);
    }
}
