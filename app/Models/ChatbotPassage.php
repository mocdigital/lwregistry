<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answerable piece of a document.
 */
final class ChatbotPassage extends Model
{
    protected $fillable = ['document_id', 'ordinal', 'content', 'searchable'];

    protected $casts = ['ordinal' => 'integer'];

    /**
     * Fold text to the form questions are matched against.
     *
     * Lowercase, accents removed, punctuation to spaces. Applied to both the
     * passage at upload and the question at search time — the same function on
     * both sides, because two normalisations that drift apart stop matching
     * each other.
     */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);

        // "société" and "societe" are the same word to somebody typing quickly.
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        if (false !== $folded) {
            $text = $folded;
        }

        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;

        return mb_trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * @return BelongsTo<ChatbotDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ChatbotDocument::class, 'document_id');
    }
}
