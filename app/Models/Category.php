<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $business_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Category extends Model implements HasMedia
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use InteractsWithMedia;

    /**
     * The band behind a category's title on /categories.
     *
     * Every category shared one hero — the site's stock video — so browsing
     * from Construction to Churches & Ministries changed the heading and
     * nothing else. An image per category is the difference between a filter
     * and a destination.
     */
    public const COVER_COLLECTION = 'cover';

    protected $fillable = [
        'name',
        'slug',
        'business_count',
    ];

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /**
     * One image, replaced rather than appended: a category has a cover, not a
     * gallery.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COVER_COLLECTION)
            ->useDisk('public')
            ->singleFile()
            ->registerMediaConversions(function (): void {
                /*
                 * `nonQueued`: QUEUE_CONNECTION is `database` and a worker is
                 * not guaranteed, and a hero that 404s until somebody runs a
                 * queue is worse than one that costs a second on upload. Same
                 * reasoning as Sbo's own conversions.
                 */
                $this->addMediaConversion('banner')->width(1600)->height(600)->nonQueued();
            });
    }

    /**
     * The cover URL, or null when none is set — the screen then falls back to
     * the shared hero rather than showing a gap.
     */
    public function coverUrl(): ?string
    {
        $media = $this->getFirstMedia(self::COVER_COLLECTION);

        if (null === $media) {
            return null;
        }

        if ($media->hasGeneratedConversion('banner') && $this->mediaFileExists($media, 'banner')) {
            return $media->getUrl('banner');
        }

        return $this->mediaFileExists($media) ? $media->getUrl() : null;
    }

    /** RFQs filed under this category — used for the procurement sidebar counts. */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    private function mediaFileExists(Media $media, string $conversion = ''): bool
    {
        $disk = '' === $conversion ? $media->disk : $media->conversions_disk;

        return Storage::disk($disk)->exists($media->getPathRelativeToRoot($conversion));
    }
}
