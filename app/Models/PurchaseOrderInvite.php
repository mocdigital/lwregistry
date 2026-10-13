<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseOrderInvite extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'purchase_order_id',
        'invited_sbo_id',
        'invited_user_id',
        'invited_email',
        'invited_by_sbo_id',
        'status',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function invitedSbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class, 'invited_sbo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function invitedBySbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class, 'invited_by_sbo_id');
    }
}
