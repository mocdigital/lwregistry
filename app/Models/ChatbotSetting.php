<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A handful of on/off choices for the assistant, by name.
 */
final class ChatbotSetting extends Model
{
    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['name', 'value'];

    /** Whether an admin reply is learned at once rather than held for review. */
    public const AUTO_LEARN = 'auto_learn';

    public static function bool(string $name, bool $default = false): bool
    {
        $row = static::find($name);

        return null === $row ? $default : (bool) $row->value;
    }

    public static function put(string $name, bool $value): void
    {
        static::updateOrCreate(['name' => $name], ['value' => $value ? '1' : '']);
    }
}
