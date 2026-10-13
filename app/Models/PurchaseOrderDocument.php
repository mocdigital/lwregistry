<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to a procurement request.
 *
 * The Documents tab drew two hardcoded sections and a "Download Selected Items"
 * button with nothing behind it, on a model that could hold a single file.
 */
final class PurchaseOrderDocument extends Model
{
    public const SECTION_PROJECT = 'project';

    public const SECTION_ATTACHMENT = 'attachment';

    protected $fillable = [
        'purchase_order_id',
        'section',
        'name',
        'path',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk('public')->exists($this->path);
    }

    /** "2.4 MB" — the size the list shows beside each file. */
    public function humanSize(): ?string
    {
        if (null === $this->size) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $this->size;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 && $unit > 0 ? 1 : 0) . ' ' . $units[$unit];
    }
}
