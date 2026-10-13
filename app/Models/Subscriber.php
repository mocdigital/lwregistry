<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who asked to hear about new opportunities.
 *
 * Not a user. Most of them are not members — the card that collects them sits
 * on the public home page, which is the point.
 */
final class Subscriber extends Model
{
    public const SOURCE_HOME_RAIL = 'home_rail';

    protected $fillable = [
        'name',
        'email',
        'source',
        'user_id',
        'ip_address',
        'unsubscribed_at',
    ];

    protected $casts = [
        'unsubscribed_at' => 'datetime',
    ];

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('unsubscribed_at');
    }

    public function isActive(): bool
    {
        return null === $this->unsubscribed_at;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
