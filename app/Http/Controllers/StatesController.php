<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The states of one country, for the registration wizard's second select.
 *
 * Fetched rather than shipped with the page: `states` holds 5,250 rows across
 * 250 countries, and sending all of them so that one can be used would put
 * ~130KB on a form nobody has started filling in yet.
 *
 * Reference data, so it is cached hard and served to guests — the wizard is
 * where an account is created, and requiring one to read it is circular.
 */
final class StatesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $name = (string) $request->query('country', '');

        if ('' === mb_trim($name)) {
            return response()->json(['states' => []]);
        }

        $states = cache()->remember(
            'states.for.' . mb_strtolower(mb_trim($name)),
            now()->addDay(),
            function () use ($name): array {
                $country = Country::query()->where('name', mb_trim($name))->first();

                if (null === $country) {
                    return [];
                }

                return State::query()
                    ->where('country_id', $country->id)
                    ->pluck('name')
                    ->all();
            },
        );

        /*
         * An empty list is a real answer — city-states and small territories
         * have no subdivisions in the dataset — and the client falls back to a
         * free-text box rather than showing a dropdown with nothing in it.
         */
        return response()->json(['states' => $states]);
    }
}
