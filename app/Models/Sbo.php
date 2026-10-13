<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Countries;
use App\Support\SboComplianceRequirements;
use App\Support\SboIndustries;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class Sbo extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;

    /**
     * The profile's media gallery tabs, in display order.
     *
     * Keys are media collection names, values are the tab labels. The four
     * were hard-coded in BusinessProfileView.vue and identical on every
     * listing; they are real per-SBO collections now. A tab with no media is
     * not rendered, so a business that only uploads office photos gets one
     * tab rather than three empty ones.
     */
    /*
     * Labels are translated where they are read, not here: a `const` is
     * evaluated once at class load, before the request's locale is known.
     */
    public const GALLERY_COLLECTIONS = [
        'gallery_workspace' => 'profile.gallery.workspace',
        'gallery_team_events' => 'profile.gallery.team_events',
        'gallery_product_shots' => 'profile.gallery.product_shots',
        'gallery_office' => 'profile.gallery.office',
    ];

    /**
     * Card branding: the wide cover photo and the round logo that sits over it.
     *
     * Every listing card in the product draws both — the directory, the home
     * page's featured row, the category grids, the profile hero. None of it
     * existed on the model, so HomeView.vue rotated three stock Figma photos
     * and three invented partner logos across every business in the registry.
     *
     * Single-file: a listing has one cover and one logo, and re-uploading
     * replaces rather than accumulates.
     */
    public const LOGO_COLLECTION = 'logo';

    public const COVER_COLLECTION = 'cover';

    public const BRANDING_COLLECTIONS = [
        self::LOGO_COLLECTION => 'profile.branding.logo',
        self::COVER_COLLECTION => 'profile.branding.cover',
    ];

    /** The two entity types a business registers as. */
    public const ENTITY_SBO = 'SBO';

    public const ENTITY_COB = 'COB';

    /** entity_type => human label, in the order the registration form offers. */
    public const ENTITY_TYPES = [
        self::ENTITY_SBO => 'SBO (Strategic Business Organization)',
        self::ENTITY_COB => 'COB (Citizen Owned Business)',
    ];

    protected $fillable = [
        'name',
        'slug',
        'rc_number',
        'tax_number',
        'business_type',
        'entity_type',
        'legal_structure',
        'trading_name',
        'registration_number',
        'category',
        'ministry',
        'industry',
        'region',
        'address',
        'city',
        'state',
        'country',
        'latitude',
        'longitude',
        'country_id',
        'premise_type',
        'established_at',
        'website',
        'social_links',
        'staff_count',
        'business_model',
        'primary_contact_name',
        'primary_contact_email',
        'owner_surname',
        'owner_first_name',
        'owner_country',
        'owner_residential_address',
        'church_zone',
        'zone_id',
        'referred_via_code',
        'local_assembly',
        'years_as_member',
        'zonal_pastor',
        'local_pastor',
        'phone',
        'kingschat_handle',
        'directors',
        'description',
        'is_active',
        // The registry's own listing; see App\Support\RegistrySbo.
        'is_registry',
        'created_by',
        'status',
        'application_id',
        'received_by',
        'date_received',
        'verification_status',
        'approved_by',
        'date_approved',
        'moc_registration_number',
        'decline_reason',
        'info_request',
        'resubmitted_at',
        'code_of_conduct_agreed_at',
        'documents_status',
        'payment_status',
        'documents_compliant_at',
        'payment_confirmed_at',
        'verified_notified_at',
        'share_structure',
        'share_capital_type',
        'issued_shares_volume',
        'per_share_value',
        'calculated_fee',
        'fee_band',
        'payment_reference',
        'payment_submitted_at',
    ];

    protected $casts = [
        'directors' => 'array',        // 👈 KEY PART
        'social_links' => 'array',
        'is_active' => 'boolean',
        'is_registry' => 'boolean',
        'established_at' => 'date',
        'staff_count' => 'integer',
        'date_received' => 'datetime',
        'date_approved' => 'datetime',
        'resubmitted_at' => 'datetime',
        'code_of_conduct_agreed_at' => 'datetime',
        'documents_compliant_at' => 'datetime',
        'payment_confirmed_at' => 'datetime',
        'verified_notified_at' => 'datetime',
        'issued_shares_volume' => 'integer',
        'per_share_value' => 'decimal:2',
        'calculated_fee' => 'integer',
        'payment_submitted_at' => 'datetime',
        'country_id' => 'integer',
        'zone_id' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'geocoded_at' => 'datetime',
    ];

    /**
     * What a logo upload may be, wherever it is collected.
     *
     * SVG is accepted for logos only. It is a scripting-capable format, so it
     * is served from the public disk as a download-safe asset and never inlined
     * into a page.
     *
     * @return array<int, mixed>
     */
    public static function logoUploadRules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'max:5120',
            'mimes:jpg,jpeg,png,webp,svg',
        ];
    }

    /**
     * Generate a sequential, human-readable application id, e.g. MBN-2026-0001.
     */
    public static function generateApplicationId(): string
    {
        $year = now()->format('Y');
        $prefix = 'MBN-' . $year . '-';

        $last = self::where('application_id', 'like', $prefix . '%')
            ->orderByDesc('application_id')
            ->value('application_id');

        $next = $last ? ((int) mb_substr($last, -4)) + 1 : 1;

        return $prefix . mb_str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public static function generateMocRegistrationNumber(): string
    {
        $year = now()->format('Y');
        $prefix = 'MOC-' . $year . '-';

        $last = self::where('moc_registration_number', 'like', $prefix . '%')
            ->orderByDesc('moc_registration_number')
            ->value('moc_registration_number');

        $next = $last ? ((int) mb_substr($last, -4)) + 1 : 1;

        do {
            $number = $prefix . mb_str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (self::where('moc_registration_number', $number)->exists());

        return $number;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The SBO's country as a structured FK. The free-text `country` name is
     * kept for display; this drives country-scoped config (e.g. fees).
     *
     * Named `countryRelation` (not `country`) so it never collides with the
     * free-text `country` string attribute — mirrors Business's convention.
     */
    public function countryRelation(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * The church zone this SBO registered under. The free-text `church_zone`
     * name is kept for display; this FK drives referral tracking and the
     * zone-momentum dashboard.
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPendingApproval(): bool
    {
        return 'pending' === $this->verification_status;
    }

    public function isApproved(): bool
    {
        return 'approved' === $this->verification_status;
    }

    public function isDeclined(): bool
    {
        return 'declined' === $this->verification_status;
    }

    public function isInfoRequested(): bool
    {
        return 'info_requested' === $this->verification_status;
    }

    /**
     * Documents are compliant when every active, required document type has an
     * approved submission for this SBO.
     *
     * Grandfathering: SBOs marked compliant before the document-upload feature
     * existed (documents_compliant_at set, but no submissions on record) keep
     * their compliant status so nothing regresses.
     */
    public function documentsCompliant(): bool
    {
        $requiredTypeIds = SboComplianceRequirements::requiredFor($this)->pluck('id');

        // No requirements configured yet → fall back to the admin's manual flag.
        if ($requiredTypeIds->isEmpty()) {
            return 'compliant' === $this->documents_status
                && null !== $this->documents_compliant_at;
        }

        $approvedTypeIds = $this->complianceDocuments()
            ->where('status', 'approved')
            ->pluck('compliance_document_type_id');

        // Grandfather: previously confirmed compliant and has never uploaded
        // anything under the new flow.
        if (null !== $this->documents_compliant_at && $this->complianceDocuments()->doesntExist()) {
            return true;
        }

        // Every required type must have an approved submission.
        return $requiredTypeIds->every(fn (int $id): bool => $approvedTypeIds->contains($id));
    }

    public function feesPaid(): bool
    {
        return 'paid' === $this->payment_status
            && null !== $this->payment_confirmed_at;
    }

    /** The full label for this business's entity type; defaults to SBO. */
    public function entityTypeLabel(): string
    {
        return self::ENTITY_TYPES[$this->entity_type]
            ?? self::ENTITY_TYPES[self::ENTITY_SBO];
    }

    /**
     * The owner has submitted proof of payment that an admin has not yet
     * confirmed (payment still shows unpaid).
     */
    public function hasSubmittedPayment(): bool
    {
        return null !== $this->payment_submitted_at && ! $this->feesPaid();
    }

    /**
     * The fee is settled OR the owner has made a payment that is awaiting an
     * admin's confirmation. The application steps advance on THIS — an owner who
     * has paid does not wait for the admin to confirm before uploading their
     * documents; the admin's review happens once every step is done.
     */
    public function feeSettledOrSubmitted(): bool
    {
        return $this->feesPaid() || null !== $this->payment_submitted_at;
    }

    /**
     * Every required document has been UPLOADED (any status), as opposed to
     * documentsCompliant() which needs each one APPROVED. This is "the owner has
     * done their part of the documents step" — the approval is the admin's part,
     * at the end.
     */
    public function documentsSubmitted(): bool
    {
        if ($this->documentsCompliant()) {
            return true;
        }

        $requiredTypeIds = SboComplianceRequirements::requiredFor($this)->pluck('id');

        // No requirements configured → fall back to the admin's manual flag,
        // exactly as documentsCompliant() does. (Every country ships with at
        // least one requirement, so this only arises before configuration.)
        if ($requiredTypeIds->isEmpty()) {
            return 'compliant' === $this->documents_status;
        }

        $submittedTypeIds = $this->complianceDocuments()->pluck('compliance_document_type_id');

        return $requiredTypeIds->every(fn (int $id): bool => $submittedTypeIds->contains($id));
    }

    /**
     * Fully verified means an admin has approved the registration AND
     * documents are confirmed compliant AND the registration fees are paid.
     * All three are independent admin actions; none is implied by registration.
     */
    public function isFullyVerified(): bool
    {
        return $this->isApproved()
            && $this->documentsCompliant()
            && $this->feesPaid();
    }

    public function getIsVerifiedAttribute(): bool
    {
        return $this->isFullyVerified();
    }

    public function getAvgRatingAttribute(): float
    {
        return 0.0;
    }

    public function getTotalReviewsAttribute(): int
    {
        return 0;
    }

    public function getIs247Attribute(): bool
    {
        return false;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->primary_contact_email;
    }

    public function getUserIdAttribute(): ?int
    {
        return $this->created_by;
    }

    public function getStaffSizeAttribute(): ?string
    {
        return null !== $this->staff_count ? (string) $this->staff_count : null;
    }

    public function getYearsActiveAttribute(): ?int
    {
        return null === $this->established_at
            ? null
            : max(0, (int) $this->established_at->diffInYears(now()));
    }

    public function getViewsCountAttribute(): int
    {
        return 0;
    }

    public function getFavoritesCountAttribute(): int
    {
        return 0;
    }

    /**
     * The four profile highlight cards, derived rather than typed.
     *
     * These were a fixed array in BusinessProfileView.vue — "8 Core Staff",
     * "6 Services", "14 Yrs", "4.5 Score" — on every listing, which put them
     * in open contradiction with the Company Size and Founded rows directly
     * above on the same page. Each card now reads the same column its
     * neighbour does, so the two halves cannot disagree.
     *
     * A card with nothing behind it is omitted rather than shown as zero: a
     * business that has not listed services has not offered "0 Services".
     *
     * @return list<array{key: string, value: string, label: string}>
     */
    public function highlights(): array
    {
        $cards = [];

        if (null !== $this->staff_count) {
            $cards[] = [
                'key' => 'staff',
                'value' => (string) $this->staff_count,
                'label' => __('profile.highlights.staff'),
            ];
        }

        $services = $this->services_count ?? $this->services()->count();

        if ($services > 0) {
            $cards[] = [
                'key' => 'services',
                'value' => trans_choice('profile.highlights.service_count', $services, ['count' => $services]),
                'label' => __('profile.highlights.services'),
            ];
        }

        if (null !== $this->years_active) {
            $cards[] = [
                'key' => 'years',
                'value' => trans_choice('profile.highlights.year_count', $this->years_active, ['count' => $this->years_active]),
                'label' => __('profile.highlights.years'),
            ];
        }

        /*
         * The rating card has no source yet. SBOs carry no reviews — the
         * `reviews` table is keyed to the legacy `businesses` model — so
         * rather than print the old hard-coded 4.5 for everyone, the card is
         * omitted until SBO reviews exist. getAvgRatingAttribute() still
         * returns 0.0 and is the hook to fill in when they do.
         */

        return $cards;
    }

    /**
     * @return HasMany<SboService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(SboService::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Every place this business trades from, primary first.
     *
     * @return HasMany<SboAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(SboAddress::class)
            ->orderByDesc('is_primary')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The ISO codes for the countries this business trades in, in the order the
     * addresses are listed, without repeats.
     *
     * The card design draws a stack of country flags. It had no data behind it,
     * so the flags cycled through a fixed list of assets by card position — a
     * Lagos business could be shown flying the flag of the UAE. This is the
     * real set: one flag per COUNTRY, so three addresses in Nigeria produce one
     * flag, not three.
     *
     * Falls back to the listing's own `country` column, which is what a
     * business that has not opened the address editor still has.
     *
     * @return list<string>
     */
    public function countryCodes(): array
    {
        $codes = $this->relationLoaded('addresses')
            ? $this->addresses->pluck('country_code')
            : $this->addresses()->pluck('country_code');

        $codes = $codes->filter()->values();

        if ($codes->isEmpty()) {
            $own = Countries::codeFor($this->country);

            return null === $own ? [] : [$own];
        }

        return $codes->unique()->values()->all();
    }

    /**
     * @return HasMany<SboOpeningHour, $this>
     */
    public function openingHours(): HasMany
    {
        return $this->hasMany(SboOpeningHour::class);
    }

    /**
     * Opening hours in Monday-first reading order, skipping days the owner
     * never set and days whose row carries no usable time.
     *
     * @return list<array{day: string, time: string}>
     */
    public function openingHoursForDisplay(): array
    {
        $byDay = $this->openingHours->keyBy('day_of_week');
        $rows = [];

        foreach (SboOpeningHour::DISPLAY_ORDER as $day) {
            $hour = $byDay->get($day);
            $label = $hour?->label();

            if (null === $label) {
                continue;
            }

            $rows[] = ['day' => $hour->dayName(), 'time' => $label];
        }

        return $rows;
    }

    public function getHoursAttribute(): \Illuminate\Support\Collection
    {
        return collect($this->openingHoursForDisplay());
    }

    public function getProductsAttribute(): \Illuminate\Support\Collection
    {
        return collect();
    }

    public function getReviewsAttribute(): \Illuminate\Support\Collection
    {
        return collect();
    }

    public function getAwardsAttribute(): \Illuminate\Support\Collection
    {
        return collect();
    }

    public function getPromotionsAttribute(): \Illuminate\Support\Collection
    {
        return collect();
    }

    /*
     * hasMedia() and getFirstMediaUrl() used to be hand-written stubs here,
     * returning false and '' unconditionally, because the model had no media.
     * InteractsWithMedia supplies the real implementations — leaving the stubs
     * in place would silently override the trait and keep every gallery empty.
     */

    /** The card cover photo, or null when the owner has not set one. */
    public function coverUrl(?string $conversion = 'card'): ?string
    {
        return $this->brandingUrl(self::COVER_COLLECTION, $conversion);
    }

    /** The round logo shown over the cover, or null. */
    public function logoUrl(?string $conversion = 'thumb'): ?string
    {
        return $this->brandingUrl(self::LOGO_COLLECTION, $conversion);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::LOGO_COLLECTION)
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])
            ->registerMediaConversions(function (): void {
                /*
                 * `nonQueued`, because the default is to queue conversions and
                 * this project runs `QUEUE_CONNECTION=database`. With no worker
                 * running, a queued conversion is never generated — and the URL
                 * still points at it, so an uploaded logo rendered as a broken
                 * image. These are two small profile images; generating them in
                 * the request costs less than the class of bug it removes.
                 */
                $this->addMediaConversion('thumb')->width(192)->height(192)->nonQueued();
            });

        $this->addMediaCollection(self::COVER_COLLECTION)
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->registerMediaConversions(function (): void {
                // Card covers render ~320x160; 2x for retina. Non-queued for
                // the same reason as the logo above.
                $this->addMediaConversion('card')->width(640)->height(320)->nonQueued();
            });

        foreach (array_keys(self::GALLERY_COLLECTIONS) as $collection) {
            $this->addMediaCollection($collection)
                // The public disk: unlike compliance documents, these are
                // meant to be seen by anyone reading the profile.
                ->useDisk('public')
                ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->registerMediaConversions(function (): void {
                    // The grid renders at 400x300; the full image is only
                    // fetched when a visitor opens one.
                    $this->addMediaConversion('thumb')
                        ->width(400)
                        ->height(300);
                });
        }
    }

    /**
     * Single source of truth for the SBO's compliance state shown to the
     * owner: 'verified' once everything clears, otherwise 'provisional'.
     */
    public function complianceState(): string
    {
        return $this->isFullyVerified() ? 'verified' : 'provisional';
    }

    // ✅ NEW
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'sbo_id');
    }

    /**
     * @return HasMany<SboComplianceDocument, $this>
     */
    public function complianceDocuments(): HasMany
    {
        return $this->hasMany(SboComplianceDocument::class);
    }

    /**
     * @return HasOne<SboPaymentProof, $this>
     */
    public function paymentProof(): HasOne
    {
        return $this->hasOne(SboPaymentProof::class);
    }

    /**
     * @return HasMany<SboPaymentTransaction, $this>
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(SboPaymentTransaction::class);
    }

    public function sentConnections(): HasMany
    {
        return $this->hasMany(SboConnection::class, 'requester_sbo_id');
    }

    public function receivedConnections(): HasMany
    {
        return $this->hasMany(SboConnection::class, 'recipient_sbo_id');
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(SboMessage::class);
    }

    public function purchaseOrderInvites(): HasMany
    {
        return $this->hasMany(PurchaseOrderInvite::class, 'invited_sbo_id');
    }

    public function purchaseOrderBids(): HasMany
    {
        return $this->hasMany(PurchaseOrderBid::class, 'bidder_sbo_id');
    }

    /**
     * @return HasOne<SboSeal, $this>
     */
    public function seal(): HasOne
    {
        return $this->hasOne(SboSeal::class);
    }

    public function ensureMocRegistrationNumber(): string
    {
        if ( ! empty($this->moc_registration_number)) {
            return $this->moc_registration_number;
        }

        $this->forceFill([
            'moc_registration_number' => self::generateMocRegistrationNumber(),
        ])->save();

        return $this->moc_registration_number;
    }

    /**
     * Group conversations this SBO is a member of — industry rooms and bid
     * threads. Direct threads hang off a connection instead, so they are not
     * in this pivot.
     *
     * @return BelongsToMany<SboConversation, $this>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(
            SboConversation::class,
            'sbo_conversation_participants',
            'sbo_id',
            'conversation_id',
        )->withPivot('last_read_at')->withTimestamps();
    }

    protected static function booted(): void
    {
        self::creating(function (Sbo $sbo): void {
            /*
             * The registry's own listing is not an application. Giving it a
             * number would consume one from the sequence real businesses draw
             * from, so the first genuine applicant of the year would be
             * MBN-2026-0002 with nobody holding 0001.
             */
            if (empty($sbo->application_id) && ! $sbo->is_registry) {
                $sbo->application_id = self::generateApplicationId();
            }

            if (empty($sbo->date_received)) {
                $sbo->date_received = now();
            }

            if (empty($sbo->verification_status)) {
                $sbo->verification_status = 'pending';
            }
        });

        /*
         * Keep the industry in step with the category.
         *
         * Registration asks for the category only — the industry is the sector
         * above it — and SBOs are created from three different places (the
         * register wizard, the signed-in "New Listing" flow and the legacy
         * Livewire modal). Deriving it here rather than at each call site means
         * seeders, factories and admin edits stay consistent too.
         *
         * An industry set explicitly is left alone, so a future screen can
         * override the mapping for a business that does not sit where its
         * category suggests.
         */
        self::saving(function (Sbo $sbo): void {
            if ($sbo->isDirty('category') && ! $sbo->isDirty('industry')) {
                $sbo->industry = SboIndustries::forCategory($sbo->category);
            }
        });
    }

    /**
     * A branding image URL, or null when none is set.
     *
     * SVG logos have no raster conversions, so the original is returned for
     * those rather than a URL to a conversion that was never generated.
     */
    private function brandingUrl(string $collection, ?string $conversion): ?string
    {
        $media = $this->getFirstMedia($collection);

        if (null === $media) {
            return null;
        }

        $originalExists = $this->mediaFileExists($media);

        /*
         * The original, unless a real conversion exists.
         *
         * SVG has no raster conversions at all, and a conversion that was
         * queued but never generated is a URL to a file that is not there —
         * which is exactly how an uploaded cover ended up rendering as a
         * broken image. Falling back keeps the picture visible either way.
         */
        if (null === $conversion
            || 'image/svg+xml' === $media->mime_type
            || ! $media->hasGeneratedConversion($conversion)) {
            return $originalExists ? $media->getUrl() : null;
        }

        if ($this->mediaFileExists($media, $conversion)) {
            return $media->getUrl($conversion);
        }

        return $originalExists ? $media->getUrl() : null;
    }

    private function mediaFileExists(Media $media, string $conversion = ''): bool
    {
        $disk = '' === $conversion ? $media->disk : $media->conversions_disk;

        return Storage::disk($disk)->exists($media->getPathRelativeToRoot($conversion));
    }
}
