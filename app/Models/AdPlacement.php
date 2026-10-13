<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property string $slot
 * @property string $title
 * @property string $cta_text
 * @property string|null $cta_url
 * @property int $priority
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property int $impressions
 * @property int $clicks
 */
final class AdPlacement extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'slot',
        'title',
        'cta_text',
        'cta_url',
        'priority',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by',
    ];

    /**
     * All slot definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function slots(): array
    {
        return config('ads.slots', []);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('creative')->singleFile();
        $this->addMediaCollection('mobile_creative')->singleFile();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AdEvent::class);
    }

    /**
     * Active = flagged active AND within its scheduling window (if any).
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    public function scopeForSlot(Builder $query, string $slot): Builder
    {
        return $query->where('slot', $slot)->orderByDesc('priority')->latest();
    }

    public function imageUrl(bool $mobile = false): ?string
    {
        $media = $this->getFirstMedia($mobile ? 'mobile_creative' : 'creative');

        if (null === $media || ! $this->mediaFileExists($media)) {
            return null;
        }

        return $media->getUrl();
    }

    public function mobileImageUrl(): ?string
    {
        return $this->imageUrl(true);
    }

    /**
     * Slot definition from config/ads.php.
     *
     * @return array<string, mixed>|null
     */
    public function slotConfig(): ?array
    {
        return config('ads.slots.' . $this->slot);
    }

    public function ctr(): float
    {
        return $this->impressions > 0
            ? round(($this->clicks / $this->impressions) * 100, 2)
            : 0.0;
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    private function mediaFileExists(Media $media): bool
    {
        return Storage::disk($media->disk)->exists($media->getPathRelativeToRoot());
    }
}
