<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

final class SboSeal extends Model
{
    /** Issued at payment; the registration is still being reviewed. */
    public const STATUS_PROVISIONAL = 'provisional';

    /** Approved and documents compliant — the full seal. */
    public const STATUS_VERIFIED = 'verified';

    protected $fillable = [
        'sbo_id',
        'issued_number',
        'issued_year',
        'serial_number',
        'image_path',
        'status',
        'issued_at',
        'verified_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function isVerified(): bool
    {
        return self::STATUS_VERIFIED === $this->status;
    }

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk('public')->exists($this->image_path);
    }

    public function absolutePath(): string
    {
        return Storage::disk('public')->path($this->image_path);
    }

    public function url(): string
    {
        return route('sbo.seals.image', $this, false);
    }

    public function previewDataUri(int $size = 240): ?string
    {
        if (! $this->existsOnDisk()) {
            return null;
        }

        $contents = Storage::disk('public')->get($this->image_path);

        if (! extension_loaded('gd')) {
            return 'data:image/png;base64,' . base64_encode($contents);
        }

        $source = @imagecreatefromstring($contents);

        if (! $source instanceof \GdImage) {
            return 'data:image/png;base64,' . base64_encode($contents);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min($size / max($width, 1), $size / max($height, 1), 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $preview = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($preview, false);
        imagesavealpha($preview, true);

        $transparent = imagecolorallocatealpha($preview, 0, 0, 0, 127);
        imagefill($preview, 0, 0, $transparent);

        imagecopyresampled($preview, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($preview, null, 9);
        $previewContents = ob_get_clean();

        imagedestroy($source);
        imagedestroy($preview);

        if (! is_string($previewContents)) {
            return 'data:image/png;base64,' . base64_encode($contents);
        }

        return 'data:image/png;base64,' . base64_encode($previewContents);
    }

    public function deleteFile(): void
    {
        Storage::disk('public')->delete($this->image_path);
    }

    public function downloadName(): string
    {
        return 'loveworld-registry-seal-' . str_replace('/', '-', $this->issued_number) . '.png';
    }

    public function certificateName(): string
    {
        return 'loveworld-registry-certificate-' . str_replace('/', '-', $this->issued_number) . '.pdf';
    }
}
