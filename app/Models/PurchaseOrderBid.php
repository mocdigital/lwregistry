<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseOrderBid extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'purchase_order_id',
        'user_id',
        'business_id',
        'bidder_sbo_id',
        'proposal',
        'details',
        'attachment_path',
        'attachment_paths',
        'status',
        'submitted_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casting
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'submitted_at' => 'datetime',
        // What the five-step wizard collected, kept structured so the RFQ's
        // Interest tab can render a submitted bid back.
        'details' => 'array',
        // The bid's documents, as [{path, name}].
        'attachment_paths' => 'array',
    ];

    /**
     * Every attached document as {name, url}, newest schema first and the
     * legacy single file folded in so an older bid still shows its attachment.
     *
     * @return array<int, array{name: string, url: string}>
     */
    public function attachmentLinks(): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        $links = collect($this->attachment_paths ?? [])
            ->filter(fn ($f): bool => is_array($f) && ! empty($f['path']))
            ->map(fn (array $f): array => [
                'name' => (string) ($f['name'] ?? basename((string) $f['path'])),
                'url' => $disk->url((string) $f['path']),
            ]);

        // The legacy single upload, if it is not already one of the above.
        if (! empty($this->attachment_path) && ! $links->contains('url', $disk->url($this->attachment_path))) {
            $links->prepend(['name' => basename((string) $this->attachment_path), 'url' => $disk->url($this->attachment_path)]);
        }

        return $links->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

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
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function bidderSbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class, 'bidder_sbo_id');
    }

    /*
    |--------------------------------------------------------------------------
    | State Helpers
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }

    public function isAccepted(): bool
    {
        return 'accepted' === $this->status;
    }

    public function isRejected(): bool
    {
        return 'rejected' === $this->status;
    }

    /*
    |--------------------------------------------------------------------------
    | Business Rules
    |--------------------------------------------------------------------------
    */

    /**
     * Can this bid be accepted by an admin?
     */
    public function canBeAccepted(): bool
    {
        return $this->isPending()
            && $this->purchaseOrder
            && $this->purchaseOrder->isOpen();
    }

    /*
    |--------------------------------------------------------------------------
    | Mutators / Actions
    |--------------------------------------------------------------------------
    */

    public function markAccepted(): void
    {
        $this->update([
            'status' => 'accepted',
            'submitted_at' => $this->submitted_at ?? now(),
        ]);
    }

    public function markRejected(): void
    {
        $this->update([
            'status' => 'rejected',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }
}
