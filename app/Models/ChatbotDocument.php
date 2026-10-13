<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A file an administrator put in the knowledge bank.
 *
 * The original is kept so it can be re-read if the splitter changes, and
 * downloaded by whoever is maintaining the bank.
 */
final class ChatbotDocument extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'title', 'disk', 'path', 'original_name', 'mime_type', 'size',
        'status', 'error', 'passage_count', 'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'passage_count' => 'integer',
    ];

    /**
     * @return HasMany<ChatbotPassage, $this>
     */
    public function passages(): HasMany
    {
        return $this->hasMany(ChatbotPassage::class, 'document_id');
    }

    public function isReady(): bool
    {
        return self::STATUS_READY === $this->status;
    }

    /** Remove the file as well as the row; an orphaned upload helps nobody. */
    public function deleteWithFile(): void
    {
        // A generated document has no file (empty path); deleting "" would aim
        // at the disk root. Only touch storage when there is a real file.
        if ('' !== mb_trim((string) $this->path)) {
            Storage::disk($this->disk)->delete($this->path);
        }

        $this->delete();
    }

    /** Written by the platform (facts, Q&A, pages, learned), not uploaded. */
    public function isGenerated(): bool
    {
        return '' === mb_trim((string) $this->path);
    }
}
