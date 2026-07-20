<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Série d'événements (séances multiples). Gabarit commun (titre, type, lieu) ;
 * chaque séance reste un Event indépendant (QR, présence, compte-rendu propres).
 *
 * @property int $id
 * @property string $title
 * @property int $event_type_id
 * @property ?string $location
 */
class EventSeries extends Model
{
    /** @var list<string> */
    protected $fillable = ['title', 'event_type_id', 'location', 'created_by'];

    /** @return BelongsTo<EventType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class)->orderBy('series_position');
    }
}
