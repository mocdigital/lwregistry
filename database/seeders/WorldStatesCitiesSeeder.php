<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seeds states and cities for every country from the dr5hn
 * countries-states-cities dataset.
 *
 * The states-only JSON is committed at database/data/countries+states.json.
 * For city-level data, provide the larger JSON via:
 *   --  a local path:  php artisan db:seed --class=WorldStatesCitiesSeeder
 *       with WORLD_CSC_JSON=/abs/path/to/countries+states+cities.json
 *   --  or drop it at database/data/countries+states+cities.json
 *
 * Countries are matched to the existing `countries` table by normalized name,
 * so the seeder never depends on fragile numeric IDs. Unmatched countries are
 * reported and skipped (the join form still falls back for those).
 */
final class WorldStatesCitiesSeeder extends Seeder
{
    public function run(): void
    {
        $path = $this->resolveDatasetPath();

        if (null === $path) {
            $this->command?->error('Dataset not found. Set WORLD_CSC_JSON or place the file at database/data/countries+states+cities.json');

            return;
        }

        $this->command?->info('Loading dataset from: ' . $path);

        /** @var array<int, array<string, mixed>> $dataset */
        $dataset = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        // Map normalized country name => existing country id (one query).
        $countryIdByName = Country::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name): array => [$this->normalize($name) => $id])
            ->all();

        // Dataset name (normalized) => local countries-table name (normalized),
        // for countries the two sources spell differently.
        $aliases = [
            'eswatini' => 'swaziland',
            'guernsey' => 'guernsey and alderney',
            'ivory coast' => "cote d'ivoire (ivory coast)",
            'the gambia' => 'gambia the',
            'timor-leste' => 'east timor',
        ];

        $statesInserted = 0;
        $citiesInserted = 0;
        $unmatched = [];
        $matchedCountryNames = [];

        DB::transaction(function () use ($dataset, $countryIdByName, $aliases, &$statesInserted, &$citiesInserted, &$unmatched, &$matchedCountryNames): void {
            foreach ($dataset as $country) {
                $countryName = (string) ($country['name'] ?? '');
                $normalized = $this->normalize($countryName);
                $normalized = $aliases[$normalized] ?? $normalized;
                $countryId = $countryIdByName[$normalized] ?? null;

                if (null === $countryId) {
                    $unmatched[] = $countryName;

                    continue;
                }

                $matchedCountryNames[] = (string) Country::query()->whereKey($countryId)->value('name');
                $stateIdByName = DB::table('states')
                    ->where('country_id', $countryId)
                    ->pluck('id', 'name')
                    ->mapWithKeys(fn (int $id, string $name): array => [$this->normalize($name) => $id])
                    ->all();

                foreach (($country['states'] ?? []) as $state) {
                    // The dataset ships in two shapes: "countries+states.json" has
                    // states as plain strings; "countries+states+cities.json" has
                    // them as objects with a nested cities[] array. Handle both.
                    $stateName = is_array($state)
                        ? mb_trim((string) ($state['name'] ?? ''))
                        : mb_trim((string) $state);

                    if ('' === $stateName) {
                        continue;
                    }

                    $normalizedState = $this->normalize($stateName);
                    $stateId = $stateIdByName[$normalizedState] ?? null;

                    if (null === $stateId) {
                        $stateId = DB::table('states')->insertGetId([
                            'country_id' => $countryId,
                            'name' => mb_substr($stateName, 0, 100),
                        ]);
                        $stateIdByName[$normalizedState] = $stateId;
                        $statesInserted++;
                    }

                    $cities = is_array($state) ? ($state['cities'] ?? []) : [];
                    $existingCities = DB::table('cities')
                        ->where('state_id', $stateId)
                        ->pluck('name')
                        ->map(fn (string $name): string => $this->normalize($name))
                        ->flip()
                        ->all();
                    $cityRows = [];

                    foreach ($cities as $city) {
                        $cityName = is_array($city)
                            ? mb_trim((string) ($city['name'] ?? ''))
                            : mb_trim((string) $city);

                        if ('' === $cityName) {
                            continue;
                        }

                        $normalizedCity = $this->normalize($cityName);

                        if (isset($existingCities[$normalizedCity])) {
                            continue;
                        }

                        $cityRows[] = [
                            'state_id' => $stateId,
                            'name' => mb_substr($cityName, 0, 100),
                        ];
                        $existingCities[$normalizedCity] = true;
                    }

                    foreach (array_chunk($cityRows, 1000) as $chunk) {
                        DB::table('cities')->insert($chunk);
                        $citiesInserted += count($chunk);
                    }
                }
            }
        });

        Cache::forget('join_form.location_data');

        foreach (array_unique($matchedCountryNames) as $countryName) {
            Cache::forget('join_form.states:' . mb_strtolower($countryName));
            Cache::forget('join_form.states.v2:' . mb_strtolower($countryName));
        }

        $this->command?->info("States inserted: {$statesInserted}");
        $this->command?->info("Cities inserted: {$citiesInserted}");

        if ([] !== $unmatched) {
            $this->command?->warn(count($unmatched) . ' country name(s) in the dataset did not match the countries table and were skipped:');
            $this->command?->warn(implode(', ', array_slice($unmatched, 0, 40)) . (count($unmatched) > 40 ? ' …' : ''));
        }
    }

    private function resolveDatasetPath(): ?string
    {
        $candidates = array_filter([
            env('WORLD_CSC_JSON'),
            // Full dataset (states + nested cities) is preferred when present.
            database_path('data/countries+states+cities.json'),
            // States-only fallback (much smaller); seeds states without cities.
            database_path('data/countries+states.json'),
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Normalize a country name for matching: lowercase, collapse whitespace,
     * and standardize "and"/"&" so e.g. "Antigua And Barbuda" == "Antigua and Barbuda".
     */
    private function normalize(string $name): string
    {
        $name = mb_strtolower(mb_trim($name));
        $name = str_replace('&', 'and', $name);

        return (string) preg_replace('/\s+/', ' ', $name);
    }
}
