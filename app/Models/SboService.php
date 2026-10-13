<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service a business offers, shown in the profile's Services section and
 * counted for the "Specialties Offered" highlight.
 */
final class SboService extends Model
{
    use HasFactory;

    protected $fillable = [
        'sbo_id',
        'title',
        'body',
        'position',
    ];

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
            'position' => 'integer',
        ];
    }
}
