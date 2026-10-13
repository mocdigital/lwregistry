<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Country;
use App\Models\Sbo;
use App\Models\SboAddress;
use App\Models\SboOpeningHour;
use App\Models\SboService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The props the listing-profile editor needs.
 *
 * Shared because the editor is mounted in two places — the standalone
 * /sbo/listings/{id}/edit screen and the About tab of /settings — and both
 * feed the same Vue component. Building the payload in each controller would
 * let the two drift while still posting to the same endpoints.
 */
final class SboListingProfilePayload
{
    /** Ten per tab is enough for a profile and keeps the page weight sane. */
    public const MAX_IMAGES_PER_COLLECTION = 10;

    /** Matches the endpoint's own cap; see updateAddresses. */
    public const MAX_ADDRESSES = 12;

    /**
     * @return array{sbo: array<string, mixed>, services: list<array<string, mixed>>, hours: list<array<string, mixed>>, gallery: list<array<string, mixed>>, maxImagesPerCollection: int}
     */
    public static function for(Sbo $sbo): array
    {
        $sbo->loadMissing(['services', 'openingHours', 'addresses', 'media']);

        return [
            'sbo' => [
                'id' => $sbo->id,
                'slug' => $sbo->slug,
                /*
                 * Shown read-only so the owner can see what they cannot change
                 * and why, rather than wondering where it went. Nothing here is
                 * writable — see SboListingProfileController.
                 */
                'locked' => [
                    ['label' => __('listing.locked.business_name'), 'value' => $sbo->name],
                    ['label' => __('profile.details.registration_no'), 'value' => $sbo->rc_number],
                    ['label' => __('profile.details.legal_structure'), 'value' => $sbo->legal_structure],
                    ['label' => __('listing.locked.category'), 'value' => $sbo->category],
                    ['label' => __('listing.locked.address'), 'value' => mb_trim(implode(', ', array_filter([
                        $sbo->address, $sbo->city, $sbo->state, $sbo->country,
                    ]))) ?: null],
                ],
                'description' => $sbo->description,
                'website' => $sbo->website,
                'primary_contact_email' => $sbo->primary_contact_email,
                'phone' => $sbo->phone,
                'staff_count' => $sbo->staff_count,
            ],
            /*
             * What the listing cards draw. Null until the owner uploads —
             * the cards fall back to their stock artwork rather than showing
             * a broken image.
             */
            'branding' => [
                'logoUrl' => $sbo->logoUrl(),
                'coverUrl' => $sbo->coverUrl(),
            ],
            /*
             * Every place the business trades from. The first is the primary and
             * mirrors the columns on `sbos`; the rest are what the card's flag
             * stack is drawn from — one flag per country, not per address.
             */
            'addresses' => $sbo->addresses->map(fn (SboAddress $a): array => [
                'id' => $a->id,
                'label' => $a->label,
                'address' => $a->address,
                'city' => $a->city,
                'state' => $a->state,
                'country' => $a->country,
                'country_code' => $a->country_code,
            ])->values()->all(),
            'countries' => Country::query()->orderBy('name')->pluck('name')->all(),
            'maxAddresses' => self::MAX_ADDRESSES,
            'services' => $sbo->services->map(fn (SboService $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'body' => $s->body,
            ])->all(),
            /*
             * All seven days, including the ones with no row, so the editor
             * renders a complete week. `set` distinguishes "not specified"
             * from "closed" — the profile omits the first and prints the
             * second.
             */
            'hours' => self::hours($sbo),
            /*
             * Every collection, even the empty ones — unlike the public
             * profile, which hides a tab with no photos. The owner needs the
             * empty tab in order to put the first photo in it.
             */
            'gallery' => collect(Sbo::GALLERY_COLLECTIONS)
                ->map(fn (string $label, string $collection): array => [
                    'collection' => $collection,
                    'label' => __($label),
                    'images' => $sbo->getMedia($collection)->map(fn (Media $m): array => [
                        'id' => $m->id,
                        'thumb' => $m->getUrl('thumb'),
                        'name' => $m->name,
                    ])->all(),
                ])->values()->all(),
            'maxImagesPerCollection' => self::MAX_IMAGES_PER_COLLECTION,
        ];
    }

    /**
     * @return list<array{day_of_week: int, day: string, set: bool, is_closed: bool, opens_at: ?string, closes_at: ?string}>
     */
    private static function hours(Sbo $sbo): array
    {
        $byDay = $sbo->openingHours->keyBy('day_of_week');
        $rows = [];

        foreach (SboOpeningHour::DISPLAY_ORDER as $day) {
            $hour = $byDay->get($day);

            $rows[] = [
                'day_of_week' => $day,
                'day' => __(SboOpeningHour::DAYS[$day]),
                'set' => null !== $hour,
                'is_closed' => (bool) $hour?->is_closed,
                // The <input type="time"> wants H:i, the column stores H:i:s.
                'opens_at' => $hour?->opens_at ? mb_substr($hour->opens_at, 0, 5) : null,
                'closes_at' => $hour?->closes_at ? mb_substr($hour->closes_at, 0, 5) : null,
            ];
        }

        return $rows;
    }
}
