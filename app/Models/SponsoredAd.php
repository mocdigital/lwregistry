<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int|null $business_id
 * @property string $title
 * @property string|null $description
 * @property string $cta_text
 * @property string|null $cta_url
 * @property bool $is_active
 * @property int $order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class SponsoredAd extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('ad-image')
            ->singleFile()
            ->registerMediaConversions(function (): void {
                $this->addMediaConversion('thumb')
                    ->width(400)
                    ->height(300);
            });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
