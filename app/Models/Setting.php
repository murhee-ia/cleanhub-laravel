<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Typed key/value platform settings. The known keys and their defaults live in
 * DEFAULTS, so a fresh install reads sensible limits before any row exists and
 * the admin endpoint has a fixed allow-list to validate against — an unknown
 * key can never be written.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * The complete set of tunable limits and their fallback values. Every
     * value is a positive integer, so reads and writes coerce to int.
     *
     * @var array<string, int>
     */
    public const DEFAULTS = [
        'max_file_size_mb' => 5,
        'max_active_applications' => 50,
        'max_open_reports_per_user' => 20,
    ];

    /**
     * Read one setting as an integer, falling back to its default when no row
     * has been written yet.
     */
    public static function getValue(string $key): int
    {
        $row = self::query()->where('key', $key)->first();

        return $row !== null ? (int) $row->value : (self::DEFAULTS[$key] ?? 0);
    }

    /**
     * Write one setting, creating the row on first use.
     */
    public static function setValue(string $key, int $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /**
     * Every known setting as key => current int value, defaults filled in for
     * any not yet stored. This is the shape the admin settings form reads.
     *
     * @return array<string, int>
     */
    public static function currentValues(): array
    {
        $stored = self::query()->pluck('value', 'key');

        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = isset($stored[$key]) ? (int) $stored[$key] : $default;
        }

        return $values;
    }
}
