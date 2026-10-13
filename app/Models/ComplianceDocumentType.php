<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * An admin-defined type of document the registry requires (global list).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int|null $country_id
 * @property string|null $registry_authority
 * @property string|null $source_url
 * @property string|null $source_note
 * @property bool $is_required
 * @property bool $is_active
 * @property int $sort_order
 * @property bool $is_seeded
 * @property bool $needs_admin_review
 */
final class ComplianceDocumentType extends Model
{
    protected $fillable = [
        'name',
        'description',
        'country_id',
        'registry_authority',
        'source_url',
        'source_note',
        'is_required',
        'is_active',
        'sort_order',
        'is_seeded',
        'needs_admin_review',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'country_id' => 'integer',
        'sort_order' => 'integer',
        'is_seeded' => 'boolean',
        'needs_admin_review' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SboComplianceDocument::class);
    }

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('ordered', function ($builder): void {
            $builder->orderBy('sort_order')->orderBy('name');
        });
    }
}
