<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SboPaymentTransaction extends Model
{
    protected $fillable = [
        'sbo_id',
        'provider',
        'local_reference',
        'provider_reference',
        'amount',
        'currency',
        'gateway_status',
        'review_status',
        'raw_payload',
        'rejection_reason',
        'reviewed_by',
        'submitted_at',
        'reviewed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'raw_payload' => 'array',
        'submitted_at' => 'datetime',
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

    public function isPendingReview(): bool
    {
        return 'pending' === $this->review_status;
    }

    public function isApproved(): bool
    {
        return 'approved' === $this->review_status;
    }

    public function isRejected(): bool
    {
        return 'rejected' === $this->review_status;
    }
}
