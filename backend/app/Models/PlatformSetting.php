<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Roumdoul's own settings (how restaurants pay their plan, contacts). Edited in /admin > Payment settings. */
class PlatformSetting extends Model
{
    public const KEYS = [
        'payment_qr_path', 'payment_account_name', 'payment_account_number', 'payment_note',
        'support_phone', 'support_telegram',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** @return array<string, string|null> every key, null when not set */
    public static function allValues(): array
    {
        $stored = Cache::rememberForever('platform-settings', fn () => static::query()->pluck('value', 'key')->all());

        return array_merge(array_fill_keys(self::KEYS, null), array_intersect_key($stored, array_flip(self::KEYS)));
    }

    public static function get(string $key): ?string
    {
        return self::allValues()[$key] ?? null;
    }

    /** @param  array<string, string|null>  $values */
    public static function put(array $values): void
    {
        foreach (array_intersect_key($values, array_flip(self::KEYS)) as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => filled($value) ? (string) $value : null]);
        }

        Cache::forget('platform-settings');
    }
}
