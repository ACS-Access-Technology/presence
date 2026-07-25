<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\FilialeScoping;
use Database\Factories\FilialeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Filiale de la holding ACS Groupe (unité de cloisonnement principale).
 *
 * Introduite par l'évolution multi-filiales (cadrage-multi-entites.md § 4.1).
 * Une filiale regroupe ses comptes (`users`), ses événements (`events`) et ses
 * types d'événement (`event_types`). Le `SuperAdmin` n'appartient à aucune
 * filiale (D-ME-6). Le référentiel `Person` reste unifié au niveau holding et
 * n'est PAS rattaché à une filiale (D-ME-1) — ne pas ajouter de relation ici.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 */
final class Filiale extends Model
{
    /** @use HasFactory<FilialeFactory> */
    use HasFactory;

    /**
     * Filiale par défaut créée à la migration : tout l'existant (comptes,
     * événements, types) y est rattaché automatiquement (D-ME-2). Sert aussi de
     * repli lorsqu'aucune filiale n'est déterminable à la création d'un événement.
     */
    public const string DEFAULT_SLUG = 'acs-groupe';

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<EventType, $this> */
    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    /**
     * Identifiant de la filiale par défaut « ACS Groupe ». Utilisé comme repli
     * lors de la création d'un événement sans filiale déterminable (ex. commande
     * CLI, import). Lève une exception si la filiale par défaut est absente :
     * c'est un invariant posé par la migration, une absence = base incohérente.
     */
    public static function defaultId(): int
    {
        $id = self::query()->where('slug', self::DEFAULT_SLUG)->value('id');

        if ($id === null) {
            throw new \RuntimeException(
                'Filiale par défaut « '.self::DEFAULT_SLUG.' » introuvable : migration multi-filiales non appliquée ?',
            );
        }

        return (int) $id;
    }

    /**
     * Filiale à affecter à une ressource créée sans `filiale_id` explicite
     * (événement, type). Ordre de résolution :
     *  1. filiale du compte authentifié (AdminFiliale / Organisateur) ;
     *  2. filiale du contexte de scoping actif (SuperAdmin ayant sélectionné une
     *     filiale dans le sélecteur de topbar) ;
     *  3. filiale par défaut (SuperAdmin « Toutes les filiales », CLI, import).
     *
     * Garantit qu'un SuperAdmin qui crée depuis un contexte filiale précis crée
     * bien DANS cette filiale, et non dans la filiale par défaut.
     */
    public static function resolveForCreation(): int
    {
        $user = auth()->user();
        if ($user instanceof User && $user->filiale_id !== null) {
            return $user->filiale_id;
        }

        $context = app(FilialeScoping::class);
        if ($context->isActive() && $context->filialeId() !== null) {
            return $context->filialeId();
        }

        return self::defaultId();
    }

    /**
     * Variante STRICTE de {@see resolveForCreation()} pour les surfaces web
     * (création d'événement/type par un humain), SANS repli silencieux sur la
     * filiale par défaut.
     *
     * Motivation (anomalie P1) : depuis le contexte « Toutes les filiales », un
     * SuperAdmin voyait son événement/type rattaché en douce à « ACS Groupe »
     * sans le moindre choix. Ici, on renvoie `null` dans ce cas pour que la couche
     * de validation EXIGE un choix explicite, plutôt que de deviner.
     *
     * Le repli défaut de `resolveForCreation()` reste, lui, le filet de sécurité
     * légitime des contextes système (CLI, import, hook `creating`) où aucun
     * humain n'est là pour choisir.
     *
     * Ordre de résolution :
     *  1. AdminFiliale / Organisateur → SA filiale, `$requested` IGNORÉ
     *     (anti-injection d'une autre filiale via le payload). `null` = compte
     *     incohérent → l'appelant refusera (fail-closed) ;
     *  2. SuperAdmin avec contexte topbar précis → cette filiale, `$requested` ignoré ;
     *  3. SuperAdmin en « Toutes » → uniquement le choix explicite `$requested`
     *     (peut être `null` → l'appelant exige un choix).
     *
     * @param  ?int  $requested  Filiale explicitement demandée dans le payload
     *                           (n'a d'effet que pour un SuperAdmin en « Toutes »).
     */
    public static function resolveForWebCreation(?int $requested = null): ?int
    {
        $user = auth()->user();

        if ($user instanceof User && ! $user->isSuperAdmin()) {
            return $user->filiale_id;
        }

        $context = app(FilialeScoping::class);
        if ($context->isActive() && $context->filialeId() !== null) {
            return $context->filialeId();
        }

        return $requested;
    }
}
