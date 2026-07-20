<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compte-rendu d'un événement (un par événement).
 *
 * @property int $id
 * @property int $event_id
 * @property ?string $body
 * @property ?int $updated_by
 */
class EventReport extends Model
{
    /** @var list<string> */
    protected $fillable = ['event_id', 'body', 'updated_by'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
