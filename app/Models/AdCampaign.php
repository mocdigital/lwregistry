<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property CarbonInterface|null $desired_start_date
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property CarbonInterface|null $payment_confirmed_at
 */
final class AdCampaign extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_PAYMENT_FAILED = 'payment_failed';

    public const STATUS_PAID_PENDING_REVIEW = 'paid_pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'ad_rate_card_id',
        'ad_placement_id',
        'slot',
        'title',
        'cta_text',
        'cta_url',
        'duration_days',
        'price',
        'currency',
        'desired_start_date',
        'starts_at',
        'ends_at',
        'status',
        'payment_reference',
        'payment_confirmed_at',
        'rejection_reason',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('desktop_creative')->singleFile();
        $this->addMediaCollection('mobile_creative')->singleFile();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<AdRateCard, $this>
     */
    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(AdRateCard::class, 'ad_rate_card_id');
    }

    /**
     * @return BelongsTo<AdPlacement, $this>
     */
    public function adPlacement(): BelongsTo
    {
        return $this->belongsTo(AdPlacement::class);
    }

    /**
     * @return HasMany<AdPaymentTransaction, $this>
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(AdPaymentTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function scopeReviewQueue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID_PENDING_REVIEW);
    }

    public function desktopImageUrl(): ?string
    {
        return $this->mediaUrl('desktop_creative');
    }

    public function mobileImageUrl(): ?string
    {
        return $this->mediaUrl('mobile_creative');
    }

    public function slotConfig(): ?array
    {
        return config('ads.slots.' . $this->slot);
    }

    public function requestedStart(): CarbonInterface
    {
        $date = $this->desired_start_date;

        if (null === $date || $date->isPast()) {
            return now();
        }

        return $date->startOfDay();
    }

    public function isPaid(): bool
    {
        return null !== $this->payment_confirmed_at;
    }

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'price' => 'integer',
            'desired_start_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'payment_confirmed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    private function mediaUrl(string $collection): ?string
    {
        $media = $this->getFirstMedia($collection);

        if (null === $media || ! $this->mediaFileExists($media)) {
            return null;
        }

        return $media->getUrl();
    }

    private function mediaFileExists(Media $media): bool
    {
        return Storage::disk($media->disk)->exists($media->getPathRelativeToRoot());
    }
}
