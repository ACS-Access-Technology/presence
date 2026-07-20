<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type d'événement (référentiel CRUD, Paramètres).
 *
 * @property int $id
 * @property string $name
 * @property string $color
 * @property bool $is_active
 * @property int $position
 */
class EventType extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'color', 'is_active', 'position'];

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
