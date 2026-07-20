<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Invitation d'une personne à un événement (n'est PAS une présence).
 *
 * @property int $id
 * @property int $event_id
 * @property int $person_id
 * @property ?int $invited_by
 * @property ?Carbon $notified_at
 */
class EventInvitation extends Model
{
    /** @var list<string> */
    protected $fillable = ['event_id', 'person_id', 'invited_by', 'notified_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
