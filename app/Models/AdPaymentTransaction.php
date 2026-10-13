<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdPaymentTransaction extends Model
{
    protected $fillable = [
        'ad_campaign_id',
        'provider',
        'local_reference',
        'provider_reference',
        'amount',
        'currency',
        'gateway_status',
        'review_status',
        'raw_payload',
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
     * @return BelongsTo<AdCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function isApproved(): bool
    {
        return 'approved' === $this->review_status;
    }
}
