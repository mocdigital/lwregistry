<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ComplianceDocumentType;
use App\Models\Country;
use App\Models\Sbo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class SboComplianceRequirements
{
    /**
     * @return Builder<ComplianceDocumentType>
     */
    public static function activeQueryFor(Sbo $sbo): Builder
    {
        $country = self::countryFor($sbo);

        return ComplianceDocumentType::query()
            ->with('country')
            ->where('is_active', true)
            ->where(function (Builder $query) use ($country): void {
                $query->whereNull('country_id');

                if (null !== $country) {
                    $query->orWhere('country_id', $country->id);
                }
            });
    }

    /**
     * @return EloquentCollection<int, ComplianceDocumentType>
     */
    public static function activeFor(Sbo $sbo): EloquentCollection
    {
        return self::activeQueryFor($sbo)->get();
    }

    /**
     * @return EloquentCollection<int, ComplianceDocumentType>
     */
    public static function requiredFor(Sbo $sbo): EloquentCollection
    {
        return self::activeQueryFor($sbo)
            ->where('is_required', true)
            ->get();
    }

    public static function appliesTo(Sbo $sbo, ComplianceDocumentType $type): bool
    {
        if ( ! $type->is_active) {
            return false;
        }

        if (null === $type->country_id) {
            return true;
        }

        return self::countryFor($sbo)?->id === $type->country_id;
    }

    public static function countryFor(Sbo $sbo): ?Country
    {
        $countryName = mb_trim((string) $sbo->country);

        if ('' === $countryName) {
            return null;
        }

        $exact = Country::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($countryName)])
            ->first();

        if ($exact instanceof Country) {
            return $exact;
        }

        $target = self::normalize($countryName);

        return Country::all()
            ->first(fn (Country $country): bool => self::normalize($country->name) === $target);
    }

    /**
     * @return Collection<int, string>
     */
    public static function requiredNamesForMail(Sbo $sbo): Collection
    {
        return self::requiredFor($sbo)
            ->pluck('name')
            ->values();
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return mb_trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
