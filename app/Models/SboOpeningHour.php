<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business's hours for one day of the week.
 *
 * Three states, and they are not the same thing:
 *   is_closed = true            -> "Closed", shown on the profile
 *   opens_at + closes_at set    -> "8:00 AM — 6:00 PM"
 *   no row for the day at all   -> not specified, omitted from the profile
 */
final class SboOpeningHour extends Model
{
    use HasFactory;

    /** 0 = Sunday (Carbon's dayOfWeek). Translation keys, resolved by dayName(). */
    public const DAYS = [
        0 => 'days.sunday',
        1 => 'days.monday',
        2 => 'days.tuesday',
        3 => 'days.wednesday',
        4 => 'days.thursday',
        5 => 'days.friday',
        6 => 'days.saturday',
    ];

    /** Monday-first, which is how the profile reads them out. */
    public const DISPLAY_ORDER = [1, 2, 3, 4, 5, 6, 0];

    protected $fillable = [
        'sbo_id',
        'day_of_week',
        'opens_at',
        'closes_at',
        'is_closed',
    ];

    public function dayName(): string
    {
        $key = self::DAYS[$this->day_of_week] ?? null;

        return null === $key ? '' : __($key);
    }

    /**
     * How the row reads on the profile: "Closed", or "8:00 AM — 6:00 PM".
     * Returns null when the row carries no usable information, so the caller
     * can drop the day rather than render an empty right-hand column.
     */
    public function label(): ?string
    {
        if ($this->is_closed) {
            return __('profile.hours.closed');
        }

        if (blank($this->opens_at) || blank($this->closes_at)) {
            return null;
        }

        return $this->format($this->opens_at) . ' — ' . $this->format($this->closes_at);
    }

    /**
     * @return BelongsTo<Sbo, $this>
     */
    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }

    protected function casts(): array
    {
        return [
            'sbo_id' => 'integer',
            'day_of_week' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    /** Stored as H:i:s; shown as "8:00 AM". */
    private function format(string $time): string
    {
        return \Illuminate\Support\Carbon::createFromFormat('H:i:s', mb_substr($time . ':00', 0, 8))
            ->format('g:i A');
    }
}
