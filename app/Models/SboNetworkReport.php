<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SboNetworkReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'reporter_sbo_id',
        'reported_sbo_id',
        'purchase_order_id',
        'message_id',
        'reason',
        'details',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function reporterSbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class, 'reporter_sbo_id');
    }

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function reportedSbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class, 'reported_sbo_id');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<SboMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SboMessage::class, 'message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
