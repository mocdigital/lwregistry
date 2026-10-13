<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\BusinessObserver;
use Carbon\Carbon;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Propaganistas\LaravelPhone\Casts\E164PhoneNumberCast;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $website
 * @property string|null $address
 * @property string|null $city
 * @property int|null $country_id
 * @property int|null $state_id
 * @property string|null $country
 * @property float|null $latitude
 * @property float|null $longitude
 * @property bool $is_verified
 * @property Carbon|null $verified_at
 * @property int|null $verified_by
 * @property bool $is_featured
 * @property bool $is_24_7
 * @property string $status
 * @property string|null $staff_size
 * @property int|null $years_active
 * @property float $avg_rating
 * @property int $total_reviews
 * @property int $views_count
 * @property int $saves_count
 * @property int $favorites_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Country|null $countryRelation
 * @property-read State|null $state
 * @property-read User|null $verifier
 */
final class Business extends Model implements HasMedia
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory, InteractsWithMedia;

    protected $guarded = [];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->registerMediaConversions(function (): void {
                $this->addMediaConversion('thumb')
                    ->width(200)
                    ->height(200);
            });

        $this->addMediaCollection('gallery')
            ->registerMediaConversions(function (): void {
                $this->addMediaConversion('thumb')
                    ->width(400)
                    ->height(300);
            });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return HasMany<BusinessHour, $this>
     */
    public function hours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }

    /**
     * @return HasMany<BusinessProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(BusinessProduct::class);
    }

    /**
     * @return HasMany<InventoryOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(InventoryOrder::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return HasMany<BusinessReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(BusinessReport::class);
    }

    /**
     * @return HasMany<BusinessAward, $this>
     */
    public function awards(): HasMany
    {
        return $this->hasMany(BusinessAward::class);
    }

    /**
     * @return HasMany<BusinessPromotion, $this>
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(BusinessPromotion::class);
    }

    /**
     * @return HasMany<BusinessView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(BusinessView::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')
            ->withTimestamps();
    }

    /**
     * @return HasMany<PurchaseOrderBid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(PurchaseOrderBid::class);
    }

    protected static function booted(): void
    {
        self::observe(BusinessObserver::class);
    }

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_featured' => 'boolean',
            'is_24_7' => 'boolean',
            'verified_at' => 'datetime',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'avg_rating' => 'decimal:2',
            'phone' => E164PhoneNumberCast::class,
        ];
    }
}
