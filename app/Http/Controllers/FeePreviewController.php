<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Country;
use App\Support\SboFeePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The registration-fee schedule for a country, so the inline calculator on the
 * business-registration form can price the applicant's fee before the business
 * record exists. The real fee is still recomputed server-side on submit.
 */
final class FeePreviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['nullable', 'string', 'max:255'],
        ]);

        $countryId = filled($data['country'] ?? null)
            ? Country::query()->where('name', $data['country'])->value('id')
            : null;

        return response()->json(
            SboFeePayload::forCountry($countryId, $request->user()),
        );
    }
}
