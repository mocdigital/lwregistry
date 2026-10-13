<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BusinessReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    public const REASON_INACCURATE = 'inaccurate';

    public const REASON_SPAM = 'spam';

    public const REASON_INAPPROPRIATE = 'inappropriate';

    public const REASON_OTHER = 'other';

    protected $fillable = [
        'business_id',
        'user_id',
        'reason',
        'details',
        'contact_requested',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'contact_requested' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function reasonOptions(): array
    {
        return [
            self::REASON_INACCURATE => 'Inaccurate information',
            self::REASON_SPAM => 'Spam or scam',
            self::REASON_INAPPROPRIATE => 'Inappropriate content',
            self::REASON_OTHER => 'Other',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
