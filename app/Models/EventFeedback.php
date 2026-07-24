<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avis post-événement (note 1-5 + commentaire libre), un par présence.
 *
 * @property int $id
 * @property int $event_id
 * @property int $attendance_id
 * @property int $rating
 * @property ?string $comment
 */
class EventFeedback extends Model
{
    // "feedback" est invariant au pluriel anglais : Str::plural() ne devine pas
    // "event_feedbacks" tout seul, d'où la table explicite.
    protected $table = 'event_feedbacks';

    /** @var list<string> */
    protected $fillable = ['event_id', 'attendance_id', 'rating', 'comment'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Attendance, $this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
