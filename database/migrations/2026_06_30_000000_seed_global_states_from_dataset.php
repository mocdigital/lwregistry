<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        $path = database_path('data/countries+states.json');

        if ( ! is_file($path)) {
            return;
        }

        /** @var array<int, array{name?: string, states?: array<int, string>}> $dataset */
        $dataset = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $countryIdByName = DB::table('countries')
            ->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name): array => [$this->normalizeCountryName($name) => $id])
            ->all();

        $aliases = $this->countryAliases();
        $cacheKeys = [];

        foreach ($dataset as $country) {
            $countryName = (string) ($country['name'] ?? '');
            $normalized = $aliases[$this->normalizeCountryName($countryName)] ?? $this->normalizeCountryName($countryName);
            $countryId = $countryIdByName[$normalized] ?? null;

            if (null === $countryId) {
                continue;
            }

            $existingStates = DB::table('states')
                ->where('country_id', $countryId)
                ->pluck('name')
                ->map(fn (string $name): string => $this->normalizeStateName($name))
                ->flip()
                ->all();

            $rows = [];

            foreach (($country['states'] ?? []) as $stateName) {
                $stateName = mb_trim((string) $stateName);

                if ('' === $stateName || isset($existingStates[$this->normalizeStateName($stateName)])) {
                    continue;
                }

                $rows[] = [
                    'country_id' => $countryId,
                    'name' => mb_substr($stateName, 0, 100),
                ];
                $existingStates[$this->normalizeStateName($stateName)] = true;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('states')->insert($chunk);
            }

            $countryName = (string) DB::table('countries')->where('id', $countryId)->value('name');
            $cacheKeys[] = 'join_form.states:' . mb_strtolower($countryName);
            $cacheKeys[] = 'join_form.states.v2:' . mb_strtolower($countryName);
        }

        foreach (array_unique($cacheKeys) as $cacheKey) {
            Cache::forget($cacheKey);
        }
    }

    public function down(): void
    {
        // Intentionally no-op: removing public location reference data could
        // break existing records that reference states by id.
    }

    private function normalizeCountryName(string $name): string
    {
        $name = mb_strtolower(mb_trim($name));
        $name = str_replace('&', 'and', $name);

        return (string) preg_replace('/\s+/', ' ', $name);
    }

    private function normalizeStateName(string $name): string
    {
        return mb_strtolower(mb_trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    /**
     * Dataset names that differ from the local countries table.
     *
     * @return array<string, string>
     */
    private function countryAliases(): array
    {
        return [
            'eswatini' => 'swaziland',
            'guernsey' => 'guernsey and alderney',
            'ivory coast' => "cote d'ivoire (ivory coast)",
            'the gambia' => 'gambia the',
            'timor-leste' => 'east timor',
        ];
    }
};
