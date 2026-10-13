<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\DataLabels;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class PurchaseOrder extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;

    /**
     * The photo shown on the opportunity cards.
     *
     * `purchase_orders` carried no image at all, so LiveOpportunities.vue
     * rotated three stock Figma photos across every request in the system —
     * a road-construction shot could illustrate a request for accountancy.
     * Single-file: one featured image per request, replaced on re-upload.
     */
    public const FEATURED_COLLECTION = 'featured';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_INVITE_ONLY = 'invite_only';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    /**
     * Currencies a budget may be quoted in.
     *
     * A closed list rather than free text: "N", "naira", "NGN" and "₦" are the
     * same currency to a reader and four different strings to everything else,
     * and a bidder filtering by budget cannot compare them.
     *
     * Espees leads because it is what the registry charges its own fees in.
     *
     * @var array<int, string>
     */
    public const CURRENCIES = ['Espees', 'NGN', 'USD', 'GBP', 'EUR', 'KES', 'GHS', 'ZAR'];

    protected $fillable = [
        'sbo_id',
        'category_id',
        'request_type',
        'title',
        'description',
        'document_path',
        'budget',
        'budget_currency',
        'quantity',
        'unit_specification',
        'delivery_location',
        'service_scope',
        'service_duration',
        'required_expertise',
        'required_documents',
        'terms',
        'order_deadline',
        // The RFP schedule; see the migration for why proposals-due stays
        // `order_deadline` rather than gaining a twin.
        'advertised_at',
        'briefing_at',
        'briefing_link',
        'briefing_location',
        'briefing_mandatory',
        'inquiry_opens_at',
        'inquiry_closes_at',
        'inquiry_responses_at',
        'final_selection_at',
        'status',
        'visibility',
        'awarded_bid_id',
        'award_note',
        'completed_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casting
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'order_deadline' => 'date',
        'completed_at' => 'datetime',
        'advertised_at' => 'date',
        // A meeting has a time; the rest of the schedule is whole days.
        'briefing_at' => 'datetime',
        'briefing_mandatory' => 'boolean',
        'inquiry_opens_at' => 'date',
        'inquiry_closes_at' => 'date',
        'inquiry_responses_at' => 'date',
        'final_selection_at' => 'date',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * SBO this RFQ belongs to
     */
    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::FEATURED_COLLECTION)
            // Public: these appear on the opportunity cards, which anyone
            // browsing the registry can see.
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->registerMediaConversions(function (): void {
                /*
                 * Cards draw ~320x180; 2x for retina. `nonQueued` because the
                 * project queues conversions by default and runs a database
                 * queue — with no worker, a queued conversion never exists and
                 * the card points at a missing file.
                 */
                $this->addMediaConversion('card')->width(640)->height(360)->nonQueued();
            });
    }

    /** The featured image, or null when the buyer did not attach one. */
    public function featuredImageUrl(): ?string
    {
        $media = $this->getFirstMedia(self::FEATURED_COLLECTION);

        if (null === $media) {
            return null;
        }

        if ($media->hasGeneratedConversion('card') && $this->mediaFileExists($media, 'card')) {
            return $media->getUrl('card');
        }

        return $this->mediaFileExists($media) ? $media->getUrl() : null;
    }

    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class, 'sbo_id');
    }

    /**
     * Category of the purchase order
     */
    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * All bids submitted for this RFQ
     */
    /**
     * @return HasMany<PurchaseOrderBid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(PurchaseOrderBid::class);
    }

    /**
     * @return HasMany<PurchaseOrderQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(PurchaseOrderQuestion::class);
    }

    /**
     * Files attached to this request.
     *
     * `document_path` on this table is the single legacy upload; these are the
     * Documents tab's real list, which a request can hold many of.
     *
     * @return HasMany<PurchaseOrderDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PurchaseOrderDocument::class)->oldest();
    }

    /**
     * @return HasMany<PurchaseOrderInvite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(PurchaseOrderInvite::class);
    }

    /**
     * @return HasMany<PurchaseOrderAccessRequest, $this>
     */
    public function accessRequests(): HasMany
    {
        return $this->hasMany(PurchaseOrderAccessRequest::class);
    }

    /**
     * Whether this member may see the request.
     *
     * A public request is open to any member; a private one only to its owner
     * and to whoever has been invited — by their business, their account, or
     * the email their account uses. A declined invite grants nothing.
     */
    public function isViewableBy(?User $user): bool
    {
        if ($this->isPublic()) {
            return true;
        }

        if (null === $user) {
            return false;
        }

        // An administrator oversees procurement and can open any request
        // without being invited or asking for access.
        if ($user->isAdmin()) {
            return true;
        }

        $sboIds = $user->sbos()->pluck('id');

        if ($sboIds->contains($this->sbo_id)) {
            return true;
        }

        return $this->invites()
            ->where('status', '!=', PurchaseOrderInvite::STATUS_DECLINED)
            ->where(function ($query) use ($user, $sboIds): void {
                $query->whereIn('invited_sbo_id', $sboIds)
                    ->orWhere('invited_user_id', $user->id)
                    ->orWhere('invited_email', $user->email);
            })
            ->exists();
    }

    /**
     * The same rule as isViewableBy, as a query scope for the listing — a
     * private request must not appear to a member who was not invited.
     *
     * @param  Builder<PurchaseOrder>  $query
     */
    public function scopeViewableBy($query, ?User $user)
    {
        if (null === $user) {
            return $query->where(function ($q): void {
                $q->whereNull('visibility')->orWhere('visibility', self::VISIBILITY_PUBLIC);
            });
        }

        $sboIds = $user->sbos()->pluck('id')->all();

        return $query->where(function ($q) use ($user, $sboIds): void {
            $q->whereNull('visibility')
                ->orWhere('visibility', self::VISIBILITY_PUBLIC)
                ->orWhereIn('sbo_id', $sboIds)
                ->orWhereHas('invites', function ($iq) use ($user, $sboIds): void {
                    $iq->where('status', '!=', PurchaseOrderInvite::STATUS_DECLINED)
                        ->where(function ($w) use ($user, $sboIds): void {
                            $w->whereIn('invited_sbo_id', $sboIds)
                                ->orWhere('invited_user_id', $user->id)
                                ->orWhere('invited_email', $user->email);
                        });
                });
        });
    }

    /**
     * Winning / awarded bid
     */
    /**
     * @return BelongsTo<PurchaseOrderBid, $this>
     */
    public function awardedBid(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderBid::class, 'awarded_bid_id');
    }

    /** Testimonials left on this request (the winner's, once awarded). */
    public function testimonials(): HasMany
    {
        return $this->hasMany(Testimonial::class);
    }

    /*
    |--------------------------------------------------------------------------
    | State Helpers
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool
    {
        return 'draft' === $this->status;
    }

    public function isOpen(): bool
    {
        return 'open' === $this->status;
    }

    public function isExpired(): bool
    {
        return $this->isOpen()
            && null !== $this->order_deadline
            && $this->order_deadline->isPast();
    }

    public function isAwarded(): bool
    {
        return 'awarded' === $this->status;
    }

    public function isCompleted(): bool
    {
        return 'completed' === $this->status;
    }

    public function isCancelled(): bool
    {
        return 'cancelled' === $this->status;
    }

    public function isPublic(): bool
    {
        return ($this->visibility ?? self::VISIBILITY_PUBLIC) === self::VISIBILITY_PUBLIC;
    }

    public function isInviteOnly(): bool
    {
        return self::VISIBILITY_INVITE_ONLY === $this->visibility;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isExpired() => __('data.status.expired'),
            $this->isOpen() => __('data.status.open'),
            $this->isAwarded() => __('data.status.awarded'),
            $this->isCompleted() => __('data.status.completed'),
            $this->isCancelled() => __('data.status.cancelled'),
            // A state nobody named: shown as stored rather than blank.
            default => (string) DataLabels::get(DataLabels::STATUS, ucfirst((string) $this->status)),
        };
    }

    public function statusBadgeClass(): string
    {
        return match (true) {
            $this->isExpired() => 'bg-secondary-subtle text-secondary',
            $this->isOpen() => 'bg-success-subtle text-success',
            $this->isAwarded() => 'bg-primary-subtle text-primary',
            $this->isCompleted() => 'bg-secondary-subtle text-secondary',
            $this->isCancelled() => 'bg-danger-subtle text-danger',
            default => 'bg-warning-subtle text-warning',
        };
    }

    public function hasAwardedBid(): bool
    {
        return null !== $this->awarded_bid_id;
    }

    /*
    |--------------------------------------------------------------------------
    | Business Rules
    |--------------------------------------------------------------------------
    */

    /**
     * Can this RFQ currently accept bids?
     */
    public function canAcceptBids(): bool
    {
        return $this->isOpen()
            && (
                null === $this->order_deadline
                || $this->order_deadline->isFuture()
            );
    }

    /**
     * Can a specific business submit a bid?
     */
    public function canBusinessBid(Business $business): bool
    {
        return $this->canAcceptBids()
            && $business->is_verified
            && $business->category_id === $this->category_id
            && ! $this->bids()
                ->where('business_id', $business->id)
                ->exists();
    }

    public function isVisibleToSbo(Sbo $sbo): bool
    {
        if ((int) $this->sbo_id === (int) $sbo->id) {
            return true;
        }

        if ($this->isPublic()) {
            return true;
        }

        return $this->invites()
            ->where('invited_sbo_id', $sbo->id)
            ->exists();
    }

    public function canSboBid(Sbo $sbo): bool
    {
        return $this->canAcceptBids()
            && $this->isVisibleToSbo($sbo)
            && (int) $this->sbo_id !== (int) $sbo->id
            && $sbo->isFullyVerified()
            && $sbo->is_active
            && 'active' === $sbo->status
            && ! $sbo->creator()->whereNotNull('suspended_at')->exists()
            && ! $this->bids()
                ->where('bidder_sbo_id', $sbo->id)
                ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeForSbo($query, Sbo $sbo)
    {
        return $query->where('sbo_id', $sbo->id);
    }

    public function scopeVisibleToAnySbo($query, array $sboIds)
    {
        return $query->where(function ($query) use ($sboIds): void {
            $query->whereNull('visibility')
                ->orWhere('visibility', self::VISIBILITY_PUBLIC)
                ->orWhereIn('sbo_id', $sboIds)
                ->orWhereHas('invites', function ($query) use ($sboIds): void {
                    $query->whereIn('invited_sbo_id', $sboIds);
                });
        });
    }

    private function mediaFileExists(Media $media, string $conversion = ''): bool
    {
        $disk = '' === $conversion ? $media->disk : $media->conversions_disk;

        return Storage::disk($disk)->exists($media->getPathRelativeToRoot($conversion));
    }
}
