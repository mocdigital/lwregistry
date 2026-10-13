<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportEnquiry extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'user_id',
        'sbo_id',
        'first_name',
        'last_name',
        'sbo_number',
        'subject',
        'message',
        'status',
        'admin_response',
        'responded_by',
        'responded_at',
        'escalated_by',
        'escalated_at',
        'done_by',
        'done_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'escalated_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_RESPONDED,
            self::STATUS_ESCALATED,
            self::STATUS_DONE,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function escalator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
