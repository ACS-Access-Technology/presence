<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Trace d'un report d'événement (ancien / nouveau créneau).
 *
 * @property int $id
 * @property int $event_id
 * @property Carbon $old_starts_at
 * @property Carbon $old_ends_at
 * @property Carbon $new_starts_at
 * @property Carbon $new_ends_at
 * @property ?string $reason
 * @property ?int $rescheduled_by
 */
class EventReschedule extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'event_id', 'old_starts_at', 'old_ends_at',
        'new_starts_at', 'new_ends_at', 'reason', 'rescheduled_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_starts_at' => 'datetime',
            'old_ends_at' => 'datetime',
            'new_starts_at' => 'datetime',
            'new_ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
