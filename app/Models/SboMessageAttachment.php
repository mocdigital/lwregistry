<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file sent in a chat.
 *
 * Bytes on the private `chat` disk, streamed through a route that checks
 * conversation membership. Nothing here is publicly addressable.
 */
final class SboMessageAttachment extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    public const KIND_DOCUMENT = 'document';

    /**
     * What may be sent, and how each is displayed.
     *
     * A closed list rather than a MIME prefix check: "starts with image/"
     * accepts image/svg+xml, which is a document full of script that browsers
     * will happily execute when it is opened in a tab.
     *
     * @var array<string, string>
     */
    public const ACCEPTED = [
        'image/jpeg' => self::KIND_IMAGE,
        'image/png' => self::KIND_IMAGE,
        'image/gif' => self::KIND_IMAGE,
        'image/webp' => self::KIND_IMAGE,

        'video/mp4' => self::KIND_VIDEO,
        'video/webm' => self::KIND_VIDEO,
        'video/quicktime' => self::KIND_VIDEO,

        'application/pdf' => self::KIND_DOCUMENT,
        'application/msword' => self::KIND_DOCUMENT,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => self::KIND_DOCUMENT,
        'application/vnd.ms-excel' => self::KIND_DOCUMENT,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => self::KIND_DOCUMENT,
        'text/plain' => self::KIND_DOCUMENT,
        'text/csv' => self::KIND_DOCUMENT,
    ];

    /**
     * The extensions the upload rule accepts.
     *
     * Laravel's `mimes:` rule checks the file's real type, not its name, so
     * this is a second gate rather than the only one.
     *
     * @var array<int, string>
     */
    public const EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'mp4', 'webm', 'mov',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv',
    ];

    /** Kilobytes. Video is the reason this is not smaller. */
    public const MAX_KILOBYTES = 25600;

    protected $fillable = [
        'message_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    /**
     * Store an upload against a message.
     *
     * The stored name is generated and the sender's is kept only as a label:
     * a filename is attacker-controlled text, and using it to build a path is
     * how one conversation's upload lands in another's directory.
     */
    public static function storeFor(SboMessage $message, UploadedFile $file): self
    {
        $mime = (string) $file->getMimeType();

        $path = $file->storeAs(
            'conversations/' . $message->conversation_id,
            Str::uuid()->toString() . '.' . mb_strtolower($file->getClientOriginalExtension()),
            'chat',
        );

        return self::create([
            'message_id' => $message->id,
            'kind' => self::ACCEPTED[$mime] ?? self::KIND_DOCUMENT,
            'disk' => 'chat',
            'path' => $path,
            // Trimmed to the column, and to what a thread can show.
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 180),
            'mime_type' => $mime,
            'size' => $file->getSize(),
        ]);
    }

    /**
     * @return BelongsTo<SboMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SboMessage::class, 'message_id');
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }
}
