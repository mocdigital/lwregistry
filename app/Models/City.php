<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $state_id
 * @property string $name
 * @property-read State $state
 */
final class City extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('ordered', function ($builder): void {
            $builder->orderBy('name');
        });
    }
}
