<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FeedbackSubmission extends Model
{
    public const TYPE_TECHNICAL = 'technical';

    public const TYPE_BUSINESS = 'business';

    public const TYPE_SUGGESTION = 'suggestion';

    public const TYPE_CONTENT = 'content';

    public const TYPE_OTHER = 'other';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const STATUS_NEW = 'new';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'kc_handle',
        'feedback_type',
        'priority',
        'subject',
        'message',
        'page_url',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_TECHNICAL => __('feedback.type.technical'),
            self::TYPE_BUSINESS => __('feedback.type.business'),
            self::TYPE_SUGGESTION => __('feedback.type.suggestion'),
            self::TYPE_CONTENT => __('feedback.type.content'),
            self::TYPE_OTHER => __('feedback.type.other'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function priorityOptions(): array
    {
        return [
            self::PRIORITY_NORMAL => __('feedback.priority.normal'),
            self::PRIORITY_HIGH => __('data.status.high'),
            self::PRIORITY_LOW => __('data.status.low'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => __('data.status.new'),
            self::STATUS_REVIEWED => __('feedback.status.reviewed'),
            self::STATUS_ARCHIVED => __('feedback.status.archived'),
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function typeLabel(): string
    {
        return self::typeOptions()[$this->feedback_type] ?? ucfirst(str_replace('_', ' ', $this->feedback_type));
    }

    public function priorityLabel(): string
    {
        return self::priorityOptions()[$this->priority] ?? ucfirst($this->priority);
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? ucfirst($this->status);
    }
}
