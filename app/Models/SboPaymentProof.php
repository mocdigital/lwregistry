<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class SboPaymentProof extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const COLLECTION = 'payment_proof';

    protected $fillable = [
        'sbo_id',
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION)
            ->useDisk('compliance')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }
}
