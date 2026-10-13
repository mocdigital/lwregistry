<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cached machine translation of one message into one language.
 *
 * See the migration for why this is cached rather than translated per request:
 * providers bill per character, and several readers sharing a language would
 * otherwise pay for the same sentence repeatedly.
 */
final class SboMessageTranslation extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'locale',
        'body',
        'source_locale',
        'provider',
    ];

    /**
     * @return BelongsTo<SboMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SboMessage::class, 'message_id');
    }

    protected function casts(): array
    {
        return ['message_id' => 'integer'];
    }
}
