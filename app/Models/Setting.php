<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglage clé/valeur (branding, fuseau, format de date…).
 *
 * @property string $key
 * @property ?string $value
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Réglages de branding avec valeurs par défaut.
     *
     * @return array{org_name: string, timezone: string, date_format: string, logo_path: ?string}
     */
    public static function branding(): array
    {
        $all = static::query()->pluck('value', 'key');

        return [
            'org_name' => $all['org_name'] ?? 'ACS Groupe',
            'timezone' => $all['timezone'] ?? (string) config('app.timezone'),
            'date_format' => $all['date_format'] ?? 'j M Y',
            'logo_path' => $all['logo_path'] ?? null,
        ];
    }
}
