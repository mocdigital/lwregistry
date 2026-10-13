<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Sbo;
use App\Support\SboListingProfilePayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Lets a business owner maintain their own public profile.
 *
 * Until this existed there was no owner-facing update path anywhere in the
 * app: `POST sbo/listings` created a listing and nothing could edit one, so a
 * typo in a description was permanent and the profile's services, gallery and
 * opening hours were a fixed array in the Vue file, identical on every SBO.
 *
 * WHAT IS AND IS NOT EDITABLE
 *
 * Open: description, website, contact email, phone, staff count, plus the
 * services, opening hours and gallery this controller introduces. These are
 * the business's own account of itself and changes go live immediately.
 *
 * Locked: name, registration number, legal structure, category, address and
 * every verification/payment column. Those are what the MoC approved — an
 * owner editing them after approval would make the seal attest to something
 * that was never reviewed. They are not merely hidden from the form; nothing
 * here writes them, so a hand-crafted request cannot reach them either.
 */
final class SboListingProfileController extends Controller
{
    public function edit(Request $request, Sbo $sbo): InertiaResponse
    {
        $this->authorizeOwner($request, $sbo);

        return Inertia::render(
            'sbo/ListingProfileEditView',
            SboListingProfilePayload::for($sbo),
        );
    }

    /** The open profile fields. Locked columns are never written here. */
    public function update(Request $request, Sbo $sbo): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:5000'],
            'website' => ['nullable', 'string', 'max:255'],
            'primary_contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Matches the sbos.staff_count integer column; 0 is legitimate for
            // an owner-operated business.
            'staff_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $sbo->update($data);

        return back()->with('status', 'listing-profile-updated');
    }

    /**
     * Replaces the whole services list in one request.
     *
     * A full replace rather than per-row endpoints because the editor lets the
     * owner add, edit, delete and reorder before saving; sending the final
     * list is what makes the order they see the order that persists.
     */
    public function updateServices(Request $request, Sbo $sbo): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        $data = $request->validate([
            'services' => ['present', 'array', 'max:20'],
            'services.*.title' => ['required', 'string', 'max:120'],
            'services.*.body' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($sbo, $data): void {
            $sbo->services()->delete();

            foreach (array_values($data['services']) as $position => $service) {
                $sbo->services()->create([
                    'title' => $service['title'],
                    'body' => $service['body'] ?? null,
                    'position' => $position,
                ]);
            }
        });

        return back()->with('status', 'listing-services-updated');
    }

    /**
     * Replaces the listing's trading addresses.
     *
     * The FIRST row is the primary, and it is mirrored back onto the columns on
     * `sbos` — registration wrote them, the geocoder caches its point on them,
     * and the directory's country filter reads them. Leaving the two to diverge
     * would let an owner move their head office to Accra and still be filtered
     * into Nigeria.
     */
    public function updateAddresses(Request $request, Sbo $sbo): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        $data = $request->validate([
            'addresses' => ['present', 'array', 'max:12'],
            'addresses.*.label' => ['nullable', 'string', 'max:80'],
            'addresses.*.address' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['required', 'string', 'max:120'],
            'addresses.*.state' => ['nullable', 'string', 'max:120'],
            // Required: the country is what the card's flag is drawn from, so
            // an address without one adds a place and no flag.
            'addresses.*.country' => ['required', 'string', 'max:120'],
        ], [
            'addresses.*.city.required' => 'Every address needs a city.',
            'addresses.*.country.required' => 'Every address needs a country — the flag on your card comes from it.',
        ]);

        DB::transaction(function () use ($sbo, $data): void {
            $sbo->addresses()->delete();

            foreach (array_values($data['addresses']) as $position => $row) {
                $sbo->addresses()->create([
                    'label' => $row['label'] ?? null,
                    'address' => $row['address'] ?? null,
                    'city' => $row['city'],
                    'state' => $row['state'] ?? null,
                    'country' => $row['country'],
                    'is_primary' => 0 === $position,
                    'position' => $position,
                ]);
            }

            $primary = $data['addresses'][0] ?? null;

            if (null === $primary) {
                return;
            }

            $moved = $sbo->address !== ($primary['address'] ?? null)
                || $sbo->city !== $primary['city']
                || $sbo->country !== $primary['country'];

            $sbo->forceFill([
                'address' => $primary['address'] ?? null,
                'city' => $primary['city'],
                'state' => $primary['state'] ?? null,
                'country' => $primary['country'],
                'country_id' => Country::query()->where('name', $primary['country'])->value('id') ?? $sbo->country_id,
            ]);

            /*
             * A cached point for the OLD address is worse than none: the map
             * would keep a pin on a building the business has left. Clearing it
             * makes Geocoder::ensure() resolve the new one on the next render.
             */
            if ($moved) {
                $sbo->forceFill(['latitude' => null, 'longitude' => null, 'geocoded_address' => null]);
            }

            $sbo->save();
        });

        return back()->with('status', 'listing-addresses-updated');
    }

    /**
     * Replaces the week's opening hours.
     *
     * A day the owner leaves unset is deleted rather than stored blank, which
     * is what lets the profile tell "we are closed Sundays" apart from "we
     * have not said anything about Sundays".
     */
    public function updateHours(Request $request, Sbo $sbo): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        $data = $request->validate([
            'hours' => ['present', 'array', 'max:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.is_closed' => ['required', 'boolean'],
            'hours.*.opens_at' => ['nullable', 'required_if:hours.*.is_closed,false', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'required_if:hours.*.is_closed,false', 'date_format:H:i', 'after:hours.*.opens_at'],
        ], [
            'hours.*.opens_at.required_if' => 'Set an opening time, or mark the day closed.',
            'hours.*.closes_at.required_if' => 'Set a closing time, or mark the day closed.',
            'hours.*.closes_at.after' => 'The closing time must be later than the opening time.',
        ]);

        DB::transaction(function () use ($sbo, $data): void {
            $sbo->openingHours()->delete();

            foreach ($data['hours'] as $row) {
                // Nothing to record for a day that is neither closed nor timed.
                if ( ! $row['is_closed'] && (blank($row['opens_at']) || blank($row['closes_at']))) {
                    continue;
                }

                $sbo->openingHours()->create([
                    'day_of_week' => $row['day_of_week'],
                    'is_closed' => $row['is_closed'],
                    'opens_at' => $row['is_closed'] ? null : $row['opens_at'],
                    'closes_at' => $row['is_closed'] ? null : $row['closes_at'],
                ]);
            }
        });

        return back()->with('status', 'listing-hours-updated');
    }

    /**
     * The card logo and cover photo.
     *
     * Separate from the gallery because both are single-file — uploading
     * replaces rather than appends — and because they are what every listing
     * card in the product draws. Before this the cards rotated three stock
     * photos and three invented partner logos across the whole registry.
     */
    public function storeBranding(Request $request, Sbo $sbo): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        $data = $request->validate([
            'collection' => ['required', Rule::in(array_keys(Sbo::BRANDING_COLLECTIONS))],
            /*
             * SVG is accepted for logos only. It is a scripting-capable format,
             * so it is served from the public disk as a download-safe asset and
             * never inlined into a page.
             */
            'image' => ['required', 'file', 'max:5120', function ($attribute, $value, $fail) use ($request): void {
                $logo = Sbo::LOGO_COLLECTION === $request->input('collection');
                $allowed = $logo
                    ? ['jpg', 'jpeg', 'png', 'webp', 'svg']
                    : ['jpg', 'jpeg', 'png', 'webp'];

                if ( ! in_array(mb_strtolower($value->getClientOriginalExtension()), $allowed, true)) {
                    $fail('Use ' . implode(', ', $allowed) . '.');
                }
            }],
        ]);

        $sbo->addMediaFromRequest('image')->toMediaCollection($data['collection']);

        return back()->with('status', 'listing-branding-updated');
    }

    public function destroyBranding(Request $request, Sbo $sbo, string $collection): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        abort_unless(array_key_exists($collection, Sbo::BRANDING_COLLECTIONS), 404);

        $sbo->clearMediaCollection($collection);

        return back()->with('status', 'listing-branding-removed');
    }

    public function storeGalleryImage(Request $request, Sbo $sbo): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        $data = $request->validate([
            'collection' => ['required', Rule::in(array_keys(Sbo::GALLERY_COLLECTIONS))],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($sbo->getMedia($data['collection'])->count() >= SboListingProfilePayload::MAX_IMAGES_PER_COLLECTION) {
            return back()->withErrors([
                'image' => 'That tab already holds ' . SboListingProfilePayload::MAX_IMAGES_PER_COLLECTION
                    . ' photos. Remove one before adding another.',
            ]);
        }

        $sbo->addMediaFromRequest('image')->toMediaCollection($data['collection']);

        return back()->with('status', 'listing-image-added');
    }

    public function destroyGalleryImage(Request $request, Sbo $sbo, Media $media): RedirectResponse
    {
        $this->authorizeOwner($request, $sbo);

        /*
         * Route-model binding resolves any media row by id, so ownership of
         * the SBO is not enough — the file has to belong to THIS listing, or
         * one owner could delete another's photos by guessing an id.
         */
        abort_unless(
            $media->model_type === $sbo->getMorphClass() && (int) $media->model_id === (int) $sbo->id,
            404,
        );

        $media->delete();

        return back()->with('status', 'listing-image-removed');
    }

    /**
     * Only the account that created the listing may edit it.
     *
     * 404 rather than 403 for a listing that is not yours: a 403 would confirm
     * the id exists.
     */
    private function authorizeOwner(Request $request, Sbo $sbo): void
    {
        abort_unless((int) $sbo->created_by === (int) $request->user()->id, 404);
    }
}
