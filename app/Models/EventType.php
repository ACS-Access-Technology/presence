<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\FilialeScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type d'événement (référentiel CRUD, Paramètres).
 *
 * Rattaché à une filiale (D-ME-3), cloisonné par le global scope {@see FilialeScope}
 * (Lot F, T-ME-20) : en contexte admin, une requête ne voit que les types de la
 * filiale courante et le route-model binding `{type}` d'une autre filiale renvoie
 * 404 (ferme l'IDOR décrit dans la revue de sécurité). Hors requête admin (cron,
 * public, CLI) le scope est un no-op — même garantie que pour {@see Event}.
 * Unicité `(filiale_id, name)` (migration event_types_unique_per_filiale).
 *
 * @property int $id
 * @property int $filiale_id
 * @property string $name
 * @property string $color
 * @property bool $is_active
 * @property int $position
 */
class EventType extends Model
{
    /**
     * Référentiel par défaut, instancié pour la filiale holding (seeder) et pour
     * toute nouvelle filiale créée (voir {@see seedDefaultsFor}) : un organisateur
     * dispose immédiatement de types utilisables, sans étape de paramétrage requise.
     *
     * @var list<array{name: string, color: string}>
     */
    public const array DEFAULTS = [
        ['name' => 'Atelier', 'color' => '#7c3aed'],
        ['name' => 'Réunion', 'color' => '#2563eb'],
        ['name' => 'Rencontre', 'color' => '#d6336c'],
        ['name' => 'Formation', 'color' => '#0e9e86'],
        ['name' => 'Conférence', 'color' => '#e0620d'],
    ];

    /** @var list<string> */
    protected $fillable = ['filiale_id', 'name', 'color', 'is_active', 'position'];

    /** Instancie le référentiel {@see DEFAULTS} pour une filiale (à sa création). */
    public static function seedDefaultsFor(Filiale $filiale): void
    {
        foreach (self::DEFAULTS as $position => $type) {
            self::create([
                'filiale_id' => $filiale->id,
                'name' => $type['name'],
                'color' => $type['color'],
                'is_active' => true,
                'position' => $position,
            ]);
        }
    }

    /**
     * À la création, renseigne `filiale_id` s'il n'est pas fourni : filiale de
     * l'utilisateur authentifié, sinon repli sur la filiale par défaut. Même
     * logique que {@see Event} : évite que la contrainte NOT NULL (Lot A) ne casse
     * la création existante. Un SuperAdmin (filiale_id NULL) retombe sur le repli.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new FilialeScope);

        static::creating(function (EventType $type): void {
            if ($type->filiale_id === null) {
                $type->filiale_id = Filiale::resolveForCreation();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Filiale, $this> */
    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** Un type est supprimable uniquement s'il n'est utilisé par aucun événement. */
    public function isDeletable(): bool
    {
        return ! $this->events()->exists();
    }
}
