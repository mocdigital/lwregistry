<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AdRateCard extends Model
{
    protected $fillable = [
        'slot',
        'label',
        'duration_days',
        'price',
        'is_active',
        'sort_order',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function slotConfig(): ?array
    {
        return config('ads.slots.' . $this->slot);
    }

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'price' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
