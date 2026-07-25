<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglage clé/valeur scopé par filiale (Lot F, T-ME-19).
 *
 * Deux familles de clés :
 *  - **branding** (nom affiché, logo, couleur d'accent) : paramétrable PAR filiale
 *    avec repli sur la holding (filiale par défaut) — toggle « hériter du branding
 *    holding » (clé `brand_inherit`, Q-ME-8). Une filiale qui hérite lit les
 *    valeurs de la holding ; sinon les siennes, avec repli clé par clé.
 *  - **globales** (fuseau horaire, format de date) : définies UNIQUEMENT au niveau
 *    holding, jamais surchargeables par filiale (cadrage § 9 Q-ME-8). Stockées sous
 *    la filiale par défaut et toujours lues depuis elle.
 *
 * @property int $filiale_id
 * @property string $key
 * @property ?string $value
 */
class Setting extends Model
{
    /** @var list<string> */
    protected $fillable = ['filiale_id', 'key', 'value'];

    /** Clés de branding personnalisables par filiale (repli holding si non défini). */
    public const array BRANDING_KEYS = ['org_name', 'logo_path', 'accent_color'];

    /** Clés globales holding : jamais scopées, jamais surchargeables par filiale. */
    public const array GLOBAL_KEYS = ['timezone', 'date_format'];

    /**
     * Lit une valeur pour une filiale donnée (défaut : holding). Aucune résolution
     * d'héritage ici — c'est une lecture brute d'une ligne `(filiale_id, key)`.
     * Utiliser {@see branding()} pour le branding effectif avec repli.
     */
    public static function get(string $key, ?int $filialeId = null, ?string $default = null): ?string
    {
        $filialeId ??= Filiale::defaultId();

        return static::query()
            ->where('filiale_id', $filialeId)
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    /** Écrit une valeur pour une filiale donnée (défaut : holding). */
    public static function set(string $key, ?string $value, ?int $filialeId = null): void
    {
        $filialeId ??= Filiale::defaultId();

        static::query()->updateOrCreate(
            ['filiale_id' => $filialeId, 'key' => $key],
            ['value' => $value],
        );
    }

    /** Une filiale hérite-t-elle du branding holding ? (défaut : oui). */
    public static function inheritsBranding(int $filialeId): bool
    {
        if ($filialeId === Filiale::defaultId()) {
            return false; // La holding EST la source ; elle n'hérite de personne.
        }

        // Absent = héritage par défaut (une filiale neuve reprend le branding holding).
        return static::get('brand_inherit', $filialeId, '1') !== '0';
    }

    /**
     * Branding EFFECTIF d'une filiale : branding propre si elle ne l'hérite pas
     * (avec repli holding clé par clé), branding holding sinon. Fuseau et format
     * de date TOUJOURS holding (globaux, Q-ME-8).
     *
     * @return array{org_name: string, timezone: string, date_format: string, logo_path: ?string, accent_color: ?string}
     */
    public static function branding(?int $filialeId = null): array
    {
        $defaultId = Filiale::defaultId();

        $holding = static::query()->where('filiale_id', $defaultId)->pluck('value', 'key');

        // Globaux : toujours depuis la holding.
        $timezone = $holding['timezone'] ?? (string) config('app.timezone');
        $dateFormat = $holding['date_format'] ?? 'j M Y';

        // Point de départ = branding holding.
        $brand = [
            'org_name' => $holding['org_name'] ?? 'ACS Groupe',
            'logo_path' => $holding['logo_path'] ?? null,
            'accent_color' => $holding['accent_color'] ?? null,
        ];

        // Surcharge par la filiale si elle n'hérite pas (repli holding clé par clé).
        if ($filialeId !== null && $filialeId !== $defaultId && ! static::inheritsBranding($filialeId)) {
            $own = static::query()->where('filiale_id', $filialeId)->pluck('value', 'key');
            foreach (self::BRANDING_KEYS as $key) {
                if (array_key_exists($key, $own->all()) && $own[$key] !== null && $own[$key] !== '') {
                    $brand[$key] = $own[$key];
                }
            }
        }

        return [
            'org_name' => $brand['org_name'],
            'timezone' => $timezone,
            'date_format' => $dateFormat,
            'logo_path' => $brand['logo_path'],
            'accent_color' => $brand['accent_color'],
        ];
    }
}
