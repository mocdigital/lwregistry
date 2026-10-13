<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question asked against a procurement request, and the buyer's answer.
 *
 * Disclosure follows what the screen states: the asker is visible to the SBO
 * that posted the RFQ, and anonymous to everyone else. That is enforced when
 * the thread is shaped for the response, not by leaving the association off.
 */
final class PurchaseOrderQuestion extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'user_id',
        'sbo_id',
        'title',
        'body',
        'answer',
        'answered_by',
        'answered_at',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function isAnswered(): bool
    {
        return null !== $this->answered_at && '' !== mb_trim((string) $this->answer);
    }
}
