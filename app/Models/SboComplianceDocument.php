<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A single document an SBO has submitted for a required document type.
 *
 * @property int $id
 * @property int $sbo_id
 * @property int $compliance_document_type_id
 * @property string $status pending | approved | rejected
 * @property string|null $rejection_reason
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 */
final class SboComplianceDocument extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const COLLECTION = 'document';

    protected $fillable = [
        'sbo_id',
        'compliance_document_type_id',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }

    /**
     * @return BelongsTo<ComplianceDocumentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ComplianceDocumentType::class, 'compliance_document_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isApproved(): bool
    {
        return 'approved' === $this->status;
    }

    public function isRejected(): bool
    {
        return 'rejected' === $this->status;
    }

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }

    public function file(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION);
    }

    /**
     * Stored on the private "compliance" disk; single file (re-upload replaces),
     * PDFs and common image formats are accepted for scanned certificates.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION)
            ->useDisk('compliance')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }
}
